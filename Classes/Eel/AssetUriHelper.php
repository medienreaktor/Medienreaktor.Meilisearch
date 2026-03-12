<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Eel;

use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Media\Domain\Model\ThumbnailConfiguration;
use Neos\Media\Domain\Service\ThumbnailService;
use Psr\Log\LoggerInterface;

/**
 * AssetUriHelper
 *
 * Generates thumbnail URIs for Meilisearch indexing. Thumbnails are always
 * generated synchronously so the index contains stable /_Resources/ paths
 * instead of /media/thumbnail/{uuid} controller URIs that become invalid
 * when thumbnail entities are cleared.
 */
class AssetUriHelper implements ProtectedContextAwareInterface
{
    /**
     * @Flow\Inject
     * @var ThumbnailService
     */
    protected $thumbnailService;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Build a relative persistent resource URI for the given asset's thumbnail.
     *
     * Forces synchronous thumbnail generation and constructs the URI manually
     * to avoid dependency on Neos.Flow.http.baseUri (unavailable in CLI).
     *
     * @param AssetInterface|AssetInterface[]|null $value
     * @param integer $width
     * @param integer $height
     * @param boolean $allowCropping
     * @param boolean $allowUpScaling
     * @param string $format
     * @return null|string
     */
    public function build($value, $width, $height, $allowCropping = true, $allowUpScaling = true, $format = null)
    {
        if (!$value instanceof AssetInterface) {
            return null;
        }

        try {
            $thumbnailConfiguration = new ThumbnailConfiguration(
                $width, $width, $height, $height,
                $allowCropping, $allowUpScaling,
                false,
                null, $format
            );

            $thumbnail = $this->thumbnailService->getThumbnail($value, $thumbnailConfiguration);
            if (!$thumbnail instanceof ImageInterface) {
                return null;
            }

            $resource = $thumbnail->getResource();
            if ($resource === null) {
                return null;
            }

            // Build relative URI matching Flow's FileSystemTarget with subdivideHashPathSegment
            $sha1 = $resource->getSha1();
            return '/_Resources/Persistent/'
                . $sha1[0] . '/' . $sha1[1] . '/' . $sha1[2] . '/' . $sha1[3]
                . '/' . $sha1 . '/' . rawurlencode($resource->getFilename());
        } catch (\Exception $e) {
            $this->logger->warning('Meilisearch AssetUriHelper: Failed to generate thumbnail URI', [
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * All methods are considered safe
     *
     * @param string $methodName
     * @return boolean
     */
    public function allowsCallOfMethod($methodName)
    {
        return true;
    }
}
