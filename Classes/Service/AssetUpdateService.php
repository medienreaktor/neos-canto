<?php
declare(strict_types=1);

namespace Flownative\Canto\Service;

/*
 * This file is part of the Flownative.Canto package.
 *
 * (c) Karsten Dambekalns, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flownative\Canto\AssetSource\CantoAssetSource;
use Flownative\Canto\Exception\AssetNotFoundException;
use Flownative\Canto\Exception\AuthenticationFailedException;
use Flownative\OAuth2\Client\OAuthClientException;
use GuzzleHttp\Exception\GuzzleException;
use Neos\Cache\Exception;
use Neos\Cache\Exception\InvalidDataException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageVariant;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\ImportedAssetRepository;
use Neos\Media\Domain\Service\AssetService;
use Neos\Media\Domain\Service\AssetSourceService;
use Neos\Media\Exception\AssetServiceException;
use Psr\Log\LoggerInterface;

/**
 * Canto asset update service for webhook handling
 */
final class AssetUpdateService {
    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var ThrowableStorageInterface
     */
    protected $throwableStorage;

    /**
     * @Flow\Inject
     * @var ImportedAssetRepository
     */
    protected $importedAssetRepository;

    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var AssetService
     */
    protected $assetService;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var AssetSourceService
     */
    protected $assetSourceService;

    public function handleEvent(string $event, array $payload): bool {
        switch ($event) {
            case 'update':
                return $this->handleAssetMetadataUpdated($payload);
            case 'add':
                return $this->handleNewAssetVersionAdded($payload);
            case 'remove':
                return $this->handleAssetRemoved($payload);
            default:
                $this->logger->debug(sprintf('Unhandled event "%s" skipped', $event), LogEnvironment::fromMethodName(__METHOD__));
        }

        return false;
    }

    public function handleAssetMetadataUpdated(array $payload): bool {
        $identifier = $this->buildIdentifier($payload['scheme'], $payload['id']);

        $importedAsset = $this->importedAssetRepository->findOneByAssetSourceIdentifierAndRemoteAssetIdentifier(CantoAssetSource::ASSET_SOURCE_IDENTIFIER, $identifier);
        if ($importedAsset === null) {
            $this->logger->debug(sprintf('Metadata update skipped on non-imported asset %s', $identifier), LogEnvironment::fromMethodName(__METHOD__));
            return true;
        }

        try {
            // Code like $localAsset->getResource()->setFilename($proxy->getFilename()) leads to a
            // "Modifications are not allowed as soon as the PersistentResource has been published or persisted."
            // error. Thus we need to replace the asset to get the new name into the system.
            $this->replaceAsset($identifier);

            // But code like this could be used to update other asset metadata:
            // $assetProxy = $this->getAssetSource()->getAssetProxyRepository()->getAssetProxy($identifier);
            // $localAssetIdentifier = $importedAsset->getLocalAssetIdentifier();
            // $localAsset = $this->assetRepository->findByIdentifier($localAssetIdentifier);
            // $localAsset->setTitle($assetProxy->getIptcProperty('Title'));
            // $localAsset->setCaption($assetProxy->getIptcProperty('CaptionAbstract'));

            $this->persistenceManager->persistAll();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function handleNewAssetVersionAdded(array $payload): bool {
        $identifier = $this->buildIdentifier($payload['scheme'], $payload['id']);

        $importedAsset = $this->importedAssetRepository->findOneByAssetSourceIdentifierAndRemoteAssetIdentifier(CantoAssetSource::ASSET_SOURCE_IDENTIFIER, $identifier);
        if ($importedAsset === null) {
            $this->logger->debug(sprintf('Version update skipped on non-imported asset %s', $identifier), LogEnvironment::fromMethodName(__METHOD__));
            return true;
        }

        try {
            $this->replaceAsset($identifier);

            $this->persistenceManager->persistAll();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }


    public function handleAssetRemoved(array $payload): bool {
        $identifier = $this->buildIdentifier($payload['scheme'], $payload['id']);
        $this->logger->warning("Removing asset");

        $importedAsset = $this->importedAssetRepository->findOneByAssetSourceIdentifierAndRemoteAssetIdentifier(CantoAssetSource::ASSET_SOURCE_IDENTIFIER, $identifier);
        if ($importedAsset === null) {
            $this->logger->debug(sprintf('Remove asset skipped on non-imported asset %s', $identifier), LogEnvironment::fromMethodName(__METHOD__));
            return true;
        }

        try {
            $this->removeAsset($identifier);

            $this->persistenceManager->persistAll();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @throws OAuthClientException
     * @throws AuthenticationFailedException
     * @throws Exception
     * @throws AssetNotFoundException
     * @throws \Neos\Flow\ResourceManagement\Exception
     * @throws GuzzleException
     * @throws InvalidDataException
     */
    private function replaceAsset(string $identifier): void {
        $importedAsset = $this->importedAssetRepository->findOneByAssetSourceIdentifierAndRemoteAssetIdentifier(CantoAssetSource::ASSET_SOURCE_IDENTIFIER, $identifier);
        $localAssetIdentifier = $importedAsset->getLocalAssetIdentifier();

        /** @var AssetInterface $localAsset */
        $localAsset = $this->assetRepository->findByIdentifier($localAssetIdentifier);
        if ($localAsset instanceof ImageVariant) {
            $this->logger->debug(sprintf('Did not replace resource on %s from %s, the local asset is an ImageVariant', $localAssetIdentifier, $identifier), LogEnvironment::fromMethodName(__METHOD__));
            return;
        }
        // we need to delete the "old" resource, so we grab it here…
        $previousResource = $localAsset->getResource();

        try {
            $this->flushProxyForAsset($identifier);
            $proxy = $this->getAssetSource()->getAssetProxyRepository()->getAssetProxy($identifier);
            $newResource = $this->resourceManager->importResource($proxy->getImportStream());
        } catch (\Exception $e) {
            $this->logger->debug(sprintf('Could not import resource for asset %s from %s, exception: %s', $localAssetIdentifier, $identifier, $this->throwableStorage->logThrowable($e)), LogEnvironment::fromMethodName(__METHOD__));
            throw $e;
        }
        $newResource->setFilename($proxy->getFilename());
        $this->assetService->replaceAssetResource($localAsset, $newResource);
        $this->logger->debug(sprintf('Replaced resource %s with %s on asset %s from Canto asset %s', $localAsset->getResource()->getSha1(), $newResource->getSha1(), $localAssetIdentifier, $identifier), LogEnvironment::fromMethodName(__METHOD__));

        // … to delete it here!
        $this->logger->debug(sprintf('Trying to delete replaced resource: %s (%s)', $previousResource->getFilename(), $previousResource->getSha1()), LogEnvironment::fromMethodName(__METHOD__));
        $this->resourceManager->deleteResource($previousResource);
    }

    /**
     * @throws OAuthClientException
     * @throws AuthenticationFailedException
     * @throws Exception
     * @throws AssetNotFoundException
     * @throws \Neos\Flow\ResourceManagement\Exception
     * @throws GuzzleException
     * @throws InvalidDataException
     */
    private function removeAsset(string $identifier): void {
        $importedAsset = $this->importedAssetRepository->findOneByAssetSourceIdentifierAndRemoteAssetIdentifier(CantoAssetSource::ASSET_SOURCE_IDENTIFIER, $identifier);
        $localAssetIdentifier = $importedAsset->getLocalAssetIdentifier();
        $this->logger->warning(sprintf('local identifier %s', $localAssetIdentifier));

        /** @var AssetInterface $localAsset */
        $localAsset = $this->assetRepository->findByIdentifier($localAssetIdentifier);
        try {
            $previousResource = $localAsset->getResource();
            $this->assetRepository->remove($localAsset);
            $this->logger->warning(sprintf('Removed asset %s', $localAssetIdentifier), LogEnvironment::fromMethodName(__METHOD__));
            $this->resourceManager->deleteResource($previousResource);
            $this->logger->warning(sprintf('deleted resource for asset %s', json_encode($localAsset)), LogEnvironment::fromMethodName(__METHOD__));

        } catch (IllegalObjectTypeException $e) {

        } catch (AssetServiceException $e) {

        }
    }

    private function buildIdentifier(string $scheme, string $identifier): string {
        return sprintf('%s-%s', $scheme, $identifier);
    }

    private function flushProxyForAsset(string $identifier): void {
        $assetProxyCache = $this->getAssetSource()->getAssetProxyCache();

        if ($assetProxyCache->has($identifier)) {
            $affectedEntriesCount = $assetProxyCache->remove($identifier);
            $this->logger->debug(sprintf('Flushed asset proxy cache entry for %s, %u affected', $identifier, $affectedEntriesCount), LogEnvironment::fromMethodName(__METHOD__));
        } else {
            $this->logger->debug(sprintf('No asset proxy cache entry for %s found', $identifier), LogEnvironment::fromMethodName(__METHOD__));
        }
    }

    private function getAssetSource(): CantoAssetSource {
        /** @var CantoAssetSource $assetSource */
        $assetSource = $this->assetSourceService->getAssetSources()[CantoAssetSource::ASSET_SOURCE_IDENTIFIER];
        $assetSource->getCantoClient()->allowClientCredentialsAuthentication(true);
        return $assetSource;
    }
}
