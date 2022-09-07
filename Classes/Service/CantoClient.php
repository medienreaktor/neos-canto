<?php
declare(strict_types=1);

namespace Flownative\Canto\Service;

/*
 * This file is part of the Flownative.Canto package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flownative\Canto\Domain\Model\AccountAuthorization;
use Flownative\Canto\Domain\Repository\AccountAuthorizationRepository;
use Flownative\Canto\Exception\AuthenticationFailedException;
use Flownative\Canto\Exception\MissingClientSecretException;
use Flownative\OAuth2\Client\Authorization;
use Flownative\OAuth2\Client\OAuthClientException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Utils;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\Exception as HttpException;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Security\Context;
use Neos\Media\Domain\Model\AssetSource\SupportsSortingInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Canto API client
 */
final class CantoClient
{
    protected bool $allowClientCredentialsAuthentication = false;

    /**
     * @Flow\Inject
     * @var Bootstrap
     */
    protected $bootstrap;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @Flow\Inject
     * @var AccountAuthorizationRepository
     */
    protected $accountAuthorizationRepository;

    /**
     * @var Authorization
     */
    private $authorization;

    /**
     * @var Client
     */
    private $httpClient;

    /**
     * @param string $apiBaseUri
     * @param string $appId
     * @param string $appSecret
     * @param string $serviceName
     */
    public function __construct(private string $apiBaseUri, protected string $appId, protected string $appSecret, private string $serviceName)
    {
        $this->httpClient = new Client(['allow_redirects' => true]);
    }

    public function allowClientCredentialsAuthentication(bool $allowed): void
    {
        $this->allowClientCredentialsAuthentication = $allowed;
    }

    /**
     * @throws MissingClientSecretException
     * @throws HttpException
     * @throws MissingActionNameException
     * @throws AuthenticationFailedException
     * @throws IdentityProviderException
     */
    private function authenticate(): void
    {
        $oAuthClient = new CantoOAuthClient($this->serviceName);

        if ($this->securityContext->isInitialized()) {
            $account = $this->securityContext->getAccount();
            $accountAuthorization = $account ? $this->accountAuthorizationRepository->findOneByFlowAccountIdentifier($account->getAccountIdentifier()) : null;

            if ($accountAuthorization instanceof AccountAuthorization) {
                $this->authorization = $oAuthClient->getAuthorization($accountAuthorization->getAuthorizationId());
            }

            if ($this->authorization === null || ($this->authorization->getAccessToken() && $this->authorization->getAccessToken()->hasExpired())) {
                $returnToUri = $this->getCurrentUri();
                $this->uriBuilder->setRequest(ActionRequest::fromHttpRequest(ServerRequest::fromGlobals()));
                $this->redirectToUri(
                    $this->uriBuilder
                        ->reset()
                        ->setCreateAbsoluteUri(true)
                        ->uriFor('needed', ['returnUri' => (string)$returnToUri], 'Authorization', 'Flownative.Canto')
                );
            }
        } elseif ($this->allowClientCredentialsAuthentication) {
            $authorizationId = Authorization::generateAuthorizationIdForClientCredentialsGrant($this->serviceName, $this->appId, $this->appSecret, '');
            $this->authorization = $oAuthClient->getAuthorization($authorizationId);
            if ($this->authorization === null || ($this->authorization->getAccessToken() && $this->authorization->getAccessToken()->hasExpired())) {
                $oAuthClient->requestAccessToken($this->serviceName, $this->appId, $this->appSecret, '');
                $this->authorization = $oAuthClient->getAuthorization($authorizationId);
            }
            if ($this->authorization === null) {
                throw new AuthenticationFailedException('Authentication failed: ' . ($result->help ?? 'Unknown cause'), 1630059881);
            }
        } else {
            throw new MissingClientSecretException('Security context not initialized and client credentials use not allowed', 1631821639);
        }
    }

    private function getCurrentUri(): UriInterface
    {
        $rh = $this->bootstrap->getActiveRequestHandler();
        if ($rh instanceof HttpRequestHandlerInterface) {
            return $rh->getHttpRequest()->getUri();
        }

        throw new \RuntimeException(sprintf('Active request handler (%s) does not implement Neos\Flow\Http\HttpRequestHandlerInterface, could not determine request URI', $rh::class), 1632465274);
    }

    private function redirectToUri(string $uri): void
    {
        header('Location: ' . $uri);
        throw new StopActionException('Canto login required', 1625222167);
    }

    /**
     * @throws AuthenticationFailedException
     * @throws GuzzleException
     * @throws HttpException
     * @throws MissingActionNameException
     * @throws MissingClientSecretException
     * @throws OAuthClientException
     * @throws IdentityProviderException
     */
    public function getFile(string $assetProxyId): ResponseInterface
    {
        [$scheme, $id] = explode('-', $assetProxyId);
        return $this->sendAuthenticatedRequest($scheme . '/' . $id);
    }

    /**
     * @TODO Implement updateFile() method.
     */
    public function updateFile(string $id, array $metadata): ResponseInterface
    {
        throw new \RuntimeException('not implemented');
    }

    /**
     * @throws AuthenticationFailedException
     * @throws GuzzleException
     * @throws HttpException
     * @throws IdentityProviderException
     * @throws MissingActionNameException
     * @throws MissingClientSecretException
     * @throws OAuthClientException
     */
    public function search(string $keyword, array $formatTypes, string $customQueryPart = '', int $offset = 0, int $limit = 50, array $orderings = []): ResponseInterface
    {
        $pathAndQuery = 'search?keyword=' . urlencode($keyword);

        $pathAndQuery .= '&limit=' . urlencode((string)$limit);
        $pathAndQuery .= '&start=' . urlencode((string)$offset);

        if ($formatTypes !== []) {
            $pathAndQuery .= '&scheme=' . urlencode(implode('|', $formatTypes));
        }

        if (isset($orderings['resource.filename'])) {
            $pathAndQuery .= '&sortBy=name';
            $pathAndQuery .= '&sortDirection=' . (($orderings['resource.filename'] === SupportsSortingInterface::ORDER_DESCENDING) ? 'descending' : 'ascending');
        }

        if (isset($orderings['lastModified'])) {
            $pathAndQuery .= '&sortBy=last_modified';
            $pathAndQuery .= '&sortDirection=' . (($orderings['lastModified'] === SupportsSortingInterface::ORDER_DESCENDING) ? 'descending' : 'ascending');
        }

        if (!empty($customQueryPart)) {
            $pathAndQuery .= '&' . $customQueryPart;
        }

        return $this->sendAuthenticatedRequest($pathAndQuery);
    }

    /**
     * @throws AuthenticationFailedException
     * @throws GuzzleException
     * @throws HttpException
     * @throws IdentityProviderException
     * @throws MissingActionNameException
     * @throws MissingClientSecretException
     * @throws OAuthClientException
     * @todo perhaps cache the result
     */
    public function getCustomFields(): array
    {
        $response = $this->sendAuthenticatedRequest('custom/field');
        if ($response->getStatusCode() === 200) {
            return Utils::jsonDecode($response->getBody()->getContents());
        }
        return [];
    }

    /**
     * @throws AuthenticationFailedException
     * @throws GuzzleException
     * @throws HttpException
     * @throws IdentityProviderException
     * @throws MissingActionNameException
     * @throws MissingClientSecretException
     * @throws OAuthClientException
     */
    public function user(): array
    {
        $response = $this->sendAuthenticatedRequest('user');
        if ($response->getStatusCode() === 200) {
            return Utils::jsonDecode($response->getBody()->getContents(), true);
        }
        return [];
    }

    /**
     * @throws AuthenticationFailedException
     * @throws GuzzleException
     * @throws HttpException
     * @throws IdentityProviderException
     * @throws MissingActionNameException
     * @throws MissingClientSecretException
     * @throws OAuthClientException
     */
    public function tree(): array
    {
        $response = $this->sendAuthenticatedRequest('tree');
        if ($response->getStatusCode() === 200) {
            return Utils::jsonDecode($response->getBody()->getContents(), true);
        }
        return [];
    }

    /**
     * @throws AuthenticationFailedException
     * @throws GuzzleException
     * @throws HttpException
     * @throws IdentityProviderException
     * @throws MissingActionNameException
     * @throws MissingClientSecretException
     * @throws OAuthClientException
     */
    public function directUri(string $assetProxyId): ?Uri
    {
        [$scheme, $id] = explode('-', $assetProxyId);

        if ($this->authorization === null) {
            $this->authenticate();
        }

        $accessToken = $this->authorization->getAccessToken();
        if ($accessToken === null) {
            throw new OAuthClientException(sprintf('Canto: Failed getting an authenticated request for client ID "%s" because the authorization contained no access token', $this->authorization->getClientId()), 1625155543);
        }

        $apiBinaryBaseUri = str_replace('/api/', '/api_binary/', $this->apiBaseUri);

        $request = new Request(
            'GET',
            $apiBinaryBaseUri . '/' . $scheme . '/' . $id . '/directuri',
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $accessToken
            ]
        );

        $response = $this->httpClient->send($request);

        if ($response->getStatusCode() === 200) {
            return new Uri($response->getBody()->getContents());
        }
        return null;
    }

    /**
     * Returns a prepared request to an OAuth 2.0 service provider using Bearer token authentication
     *
     * @param Authorization $authorization
     * @param string $uriPathAndQuery A relative URI of the web server, prepended by the base URI
     * @param string $method The HTTP method, for example "GET" or "POST"
     * @param array $bodyFields Associative array of body fields to send (optional)
     * @throws OAuthClientException
     */
    private function getAuthenticatedRequest(Authorization $authorization, string $uriPathAndQuery, string $method = 'GET', array $bodyFields = []): RequestInterface
    {
        $accessToken = $authorization->getAccessToken();
        if ($accessToken === null) {
            throw new OAuthClientException(sprintf('Canto: Failed getting an authenticated request for client ID "%s" because the authorization contained no access token', $authorization->getClientId()), 1607087086);
        }

        return new Request(
            $method,
            $this->apiBaseUri . '/' . $uriPathAndQuery,
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $authorization->getAccessToken()
            ],
            ($bodyFields !== [] ? Utils::jsonEncode($bodyFields) : '')
        );
    }

    /**
     * Sends an HTTP request to an OAuth 2.0 service provider using Bearer token authentication
     *
     * @throws AuthenticationFailedException
     * @throws GuzzleException
     * @throws HttpException
     * @throws IdentityProviderException
     * @throws MissingActionNameException
     * @throws MissingClientSecretException
     * @throws OAuthClientException
     */
    public function sendAuthenticatedRequest(string $uriPathAndQuery, string $method = 'GET', array $bodyFields = []): Response
    {
        if ($this->authorization === null) {
            $this->authenticate();
        }

        return $this->httpClient->send($this->getAuthenticatedRequest($this->authorization, $uriPathAndQuery, $method, $bodyFields));
    }
}
