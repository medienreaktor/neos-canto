<?php
declare(strict_types=1);

namespace Flownative\Canto\Command;

use Flownative\Canto\AssetSource\CantoAssetProxy;
use Flownative\Canto\AssetSource\CantoAssetProxyRepository;
use Flownative\Canto\AssetSource\CantoAssetSource;
use Flownative\Canto\Exception\AuthenticationFailedException;
use Flownative\Canto\Exception\MissingClientSecretException;
use Flownative\Canto\Service\AssetUpdateService;
use Flownative\OAuth2\Client\OAuthClientException;
use GuzzleHttp\Exception\GuzzleException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\ImportedAsset;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\ImportedAssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Media\Domain\Service\AssetSourceService;

class CantoCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var AssetSourceService
     */
    protected $assetSourceService;

    /**
     * @Flow\Inject
     * @var TagRepository
     */
    protected $tagRepository;

    /**
     * @Flow\Inject
     * @var AssetCollectionRepository
     */
    protected $assetCollectionRepository;

    /**
     * @Flow\Inject
     * @var AssetUpdateService
     */
    protected $assetUpdateService;

    /**
     * @Flow\Inject
     * @var ImportedAssetRepository
     */
    protected $importedAssetRepository;

    /**
     * @Flow\InjectConfiguration(path="mapping", package="Flownative.Canto")
     * @var array
     */
    protected array $mapping = [];

    /**
     * Tag used assets
     *
     * @param string $assetSource Name of the canto asset source
     * @param bool $quiet If set, only errors will be displayed.
     * @throws StopCommandException
     */
    public function tagUsedAssetsCommand(string $assetSource = CantoAssetSource::ASSET_SOURCE_IDENTIFIER, bool $quiet = false): void
    {
        $assetSourceIdentifier = $assetSource;
        $iterator = $this->assetRepository->findAllIterator();

        !$quiet && $this->outputLine('<b>Tagging used assets of asset source "%s" via Canto API:</b>', [$assetSourceIdentifier]);

        /** @var CantoAssetSource $cantoAssetSource */
        $cantoAssetSource = $this->assetSourceService->getAssetSources()[$assetSourceIdentifier];
        $cantoClient = $cantoAssetSource->getCantoClient();

        if (!$cantoAssetSource->isAutoTaggingEnabled()) {
            $this->outputLine('<error>Auto-tagging is disabled</error>');
            $this->quit(1);
        }

        $cantoAssetSource->getAssetProxyCache()->flush();

        foreach ($this->assetRepository->iterate($iterator) as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            if ($asset->getAssetSourceIdentifier() !== $assetSourceIdentifier) {
                continue;
            }

            $assetProxy = $asset->getAssetProxy();

            if (!$assetProxy instanceof CantoAssetProxy) {
                $this->outputLine('   error   Asset "%s" (%s) could not be accessed via Canto-API', [$asset->getLabel(), $asset->getIdentifier()]);
                continue;
            }

            $currentTags = $assetProxy->getTags();
            sort($currentTags);
            if ($asset->getUsageCount() > 0) {
                $newTags = array_unique(array_merge($currentTags, [$cantoAssetSource->getAutoTaggingInUseTag()]));
                sort($newTags);

                if ($currentTags !== $newTags) {
                    $cantoClient->updateFile($assetProxy->getIdentifier(), ['keywords' => implode(',', $newTags)]);
                    $this->outputLine('   tagged   %s %s (%s)', [$asset->getLabel(), $assetProxy->getIdentifier(), $asset->getUsageCount()]);
                } else {
                    $this->outputLine('  (tagged)  %s %s (%s)', [$asset->getLabel(), $assetProxy->getIdentifier(), $asset->getUsageCount()]);
                }
            } else {
                $newTags = array_flip($currentTags);
                unset($newTags[$cantoAssetSource->getAutoTaggingInUseTag()]);
                $newTags = array_flip($newTags);
                sort($newTags);

                if ($currentTags !== $newTags) {
                    $cantoClient->updateFile($assetProxy->getIdentifier(), ['keywords' => implode(',', $newTags)]);
                    $this->outputLine('   removed %s', [$asset->getLabel(), $asset->getUsageCount()]);
                } else {
                    $this->outputLine('  (removed) %s', [$asset->getLabel(), $asset->getUsageCount()]);
                }
            }
        }
    }

    /**
     * Bring filenames back in sync with Canto
     *
     * Compares the filename stored in Neos with the current name in Canto for
     * every imported asset and re-imports the ones that drifted apart. Webhooks
     * only fire once, so any event lost to a deployment, a failing request or a
     * blocked call stays lost - this command is the safety net for that and is
     * meant to run nightly.
     *
     * @param string $assetSource Name of the canto asset source
     * @param bool $dryRun If set, only report what would be done.
     * @param bool $quiet If set, only changes and errors will be displayed.
     * @throws StopCommandException
     */
    public function resyncFilenamesCommand(string $assetSource = CantoAssetSource::ASSET_SOURCE_IDENTIFIER, bool $dryRun = false, bool $quiet = false): void
    {
        $assetSourceIdentifier = $assetSource;

        if (!isset($this->assetSourceService->getAssetSources()[$assetSourceIdentifier])) {
            $this->outputLine('<error>Unknown asset source "%s"</error>', [$assetSourceIdentifier]);
            $this->quit(1);
        }

        /** @var CantoAssetSource $cantoAssetSource */
        $cantoAssetSource = $this->assetSourceService->getAssetSources()[$assetSourceIdentifier];
        $cantoAssetSource->getCantoClient()->allowClientCredentialsAuthentication(true);

        // the cached proxies hold the names from the time of the last import,
        // which is exactly what we are trying to detect - so start from scratch
        $cantoAssetSource->getAssetProxyCache()->flush();

        !$quiet && $this->outputLine('<b>Comparing filenames of asset source "%s" with Canto%s:</b>', [$assetSourceIdentifier, $dryRun ? ' (dry run)' : '']);

        $checked = $outdated = $updated = $failed = $unreachable = $notImported = 0;
        $iterator = $this->assetRepository->findAllIterator();

        foreach ($this->assetRepository->iterate($iterator) as $asset) {
            if (!$asset instanceof Asset || $asset->getAssetSourceIdentifier() !== $assetSourceIdentifier) {
                continue;
            }

            // the mapping to the remote asset lives in the ImportedAsset, same as in
            // AssetUpdateService - assets without one never came in through Canto
            $importedAsset = $this->importedAssetRepository->findOneByLocalAssetIdentifier($asset->getIdentifier());
            if (!$importedAsset instanceof ImportedAsset) {
                $notImported++;
                continue;
            }

            // a single unreachable asset must not abort a nightly run
            $reason = '';
            try {
                $assetProxy = $cantoAssetSource->getAssetProxyRepository()->getAssetProxy($importedAsset->getRemoteAssetIdentifier());
            } catch (\Throwable $throwable) {
                $assetProxy = null;
                $reason = $throwable->getMessage();
            }
            if (!$assetProxy instanceof CantoAssetProxy) {
                $this->outputLine('  skipped   %s (%s) could not be accessed via Canto-API%s', [$asset->getLabel(), $importedAsset->getRemoteAssetIdentifier(), $reason !== '' ? ': ' . $reason : '']);
                $unreachable++;
                continue;
            }

            $checked++;
            $currentFilename = $asset->getResource()->getFilename();
            $cantoFilename = $assetProxy->getFilename();
            if ($currentFilename === $cantoFilename) {
                continue;
            }

            $outdated++;
            if ($dryRun) {
                $this->outputLine('  outdated  %s', [$currentFilename]);
                $this->outputLine('            -> %s', [$cantoFilename]);
                continue;
            }

            if ($this->assetUpdateService->synchronizeAsset($assetProxy->getIdentifier(), $assetProxy)) {
                $this->outputLine('  updated   %s', [$currentFilename]);
                $this->outputLine('            -> %s', [$cantoFilename]);
                $updated++;
            } else {
                $this->outputLine('   error   %s could not be updated, see the log for details', [$currentFilename]);
                $failed++;
            }
        }

        !$quiet && $this->outputLine();
        !$quiet && $this->outputLine('%d checked, %d outdated, %d updated, %d failed', [$checked, $outdated, $updated, $failed]);
        !$quiet && $this->outputLine('%d not reachable in Canto (deleted there), %d without import record', [$unreachable, $notImported]);

        // assets deleted in Canto are a permanent condition and would make a nightly
        // run fail forever, so only real update failures are worth an error exit
        if ($failed > 0) {
            $this->quit(1);
        }
    }

    /**
     * Import Canto Custom Fields as Tags and Collections
     *
     * @param string $assetSourceIdentifier Name of the canto asset source
     * @param bool $quiet If set, only errors will be displayed.
     * @throws AuthenticationFailedException
     * @throws GuzzleException
     * @throws IllegalObjectTypeException
     * @throws OAuthClientException
     * @throws StopCommandException
     * @throws MissingClientSecretException
     * @throws \JsonException
     * @throws IdentityProviderException
     * @throws Exception
     * @throws MissingActionNameException
     */
    public function importCustomFieldsAsCollectionsAndTagsCommand(string $assetSourceIdentifier = CantoAssetSource::ASSET_SOURCE_IDENTIFIER, bool $quiet = true): void
    {
        !$quiet && $this->outputLine('<b>Importing custom fields as tags and asset collections via Canto API</b>');

        $customFieldsMapping = $this->mapping['customFields'];
        if (empty($customFieldsMapping)) {
            $this->outputLine('<error>No custom fields configured for mapping</error>');
            $this->quit(1);
        }

        try {
            /** @var CantoAssetSource $cantoAssetSource */
            $cantoAssetSource = $this->assetSourceService->getAssetSources()[$assetSourceIdentifier];
            $cantoClient = $cantoAssetSource->getCantoClient();
            $cantoClient->allowClientCredentialsAuthentication(true);
        } catch (\Exception) {
            $this->outputLine('<error>Canto client could not be created</error>');
            $this->quit(1);
        }

        $cantoCustomFields = $cantoClient->getCustomFields();
        foreach ($cantoCustomFields as $cantoCustomField) {
            if (array_key_exists($cantoCustomField->id, $customFieldsMapping) && $customFieldsMapping[$cantoCustomField->id]['asAssetCollection']) {
                $assetCollection = $this->assetCollectionRepository->findOneByTitle($cantoCustomField->name);

                if (!($assetCollection instanceof AssetCollection)) {
                    $assetCollection = new AssetCollection($cantoCustomField->name);
                    $this->assetCollectionRepository->add($assetCollection);
                    !$quiet && $this->outputLine('+ %s', [$cantoCustomField->name]);
                } else {
                    !$quiet && $this->outputLine('= %s', [$cantoCustomField->name]);
                }

                if ($customFieldsMapping[$cantoCustomField->id]['valuesAsTags'] !== true) {
                    continue;
                }

                foreach ($cantoCustomField->values as $cantoCustomFieldValue) {
                    if (!empty($customFieldsMapping[$cantoCustomField->id]['include']) && !in_array($cantoCustomFieldValue, $customFieldsMapping[$cantoCustomField->id]['include'], true)) {
                        continue;
                    }
                    if (!empty($customFieldsMapping[$cantoCustomField->id]['exclude']) && in_array($cantoCustomFieldValue, $customFieldsMapping[$cantoCustomField->id]['exclude'], true)) {
                        continue;
                    }

                    $tag = $this->tagRepository->findOneByLabel($cantoCustomFieldValue);

                    if ($tag === null) {
                        $tag = new Tag($cantoCustomFieldValue);
                        $this->tagRepository->add($tag);
                        $assetCollection->addTag($tag);
                        $this->assetCollectionRepository->update($assetCollection);
                        !$quiet && $this->outputLine('  + %s', [$cantoCustomFieldValue]);
                    } elseif (!$assetCollection->getTags()->contains($tag)) {
                        $assetCollection->addTag($tag);
                        $this->assetCollectionRepository->update($assetCollection);
                        !$quiet && $this->outputLine('  ~ %s', [$cantoCustomFieldValue]);
                    }
                }

                !$quiet && $this->outputLine();
            }
        }

        !$quiet && $this->outputLine('<success>Import done.</success>');
    }
}
