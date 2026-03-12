<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Media\Domain\Model\ThumbnailConfiguration;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Service\ThumbnailService;

/**
 * Serves search index thumbnails based on the stable Asset identifier.
 *
 * Unlike Neos.Media's ThumbnailController (which uses the ephemeral Thumbnail
 * entity UUID), this controller resolves thumbnails via the permanent Asset UUID.
 * This makes indexed URIs survive media:clearthumbnails and resource cleanup.
 *
 * @Flow\Scope("singleton")
 */
class ThumbnailController extends ActionController
{
    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var ThumbnailService
     */
    protected $thumbnailService;

    /**
     * Generate or retrieve a thumbnail for the given asset and redirect to its
     * persistent resource URI.
     *
     * @param string $asset The persistence identifier of the asset
     * @param int $width
     * @param int $height
     * @param bool $crop
     * @param string|null $format
     */
    public function thumbnailAction(string $asset, int $width = 600, int $height = 450, bool $crop = true, ?string $format = null): void
    {
        /** @var AssetInterface|null $assetObject */
        $assetObject = $this->assetRepository->findByIdentifier($asset);

        if (!$assetObject instanceof AssetInterface) {
            $this->response->setStatusCode(404);
            return;
        }

        $thumbnailConfiguration = new ThumbnailConfiguration(
            $width, $width, $height, $height,
            $crop, false,
            false,
            null, $format
        );

        $thumbnail = $this->thumbnailService->getThumbnail($assetObject, $thumbnailConfiguration);

        if (!$thumbnail instanceof ImageInterface || $thumbnail->getResource() === null) {
            $this->response->setStatusCode(404);
            return;
        }

        $uri = $this->thumbnailService->getUriForThumbnail($thumbnail);

        // Cache the redirect so browsers don't hit this controller on every page view
        $this->response->setHttpHeader('Cache-Control', 'public, max-age=86400');
        $this->redirectToUri($uri, 0, 301);
    }
}
