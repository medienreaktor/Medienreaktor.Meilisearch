<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Eel;

use Neos\Flow\Annotations as Flow;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\ThumbnailConfiguration;
use Neos\Media\Domain\Service\AssetService;
use Neos\Media\Domain\Service\ThumbnailService;

/**
 * AssetUriHelper
 */
class AssetUriHelper implements ProtectedContextAwareInterface
{
    /**
     * Resource publisher
     *
     * @Flow\Inject
     * @var AssetService
     */
    protected $assetService;

    /**
     * @Flow\Inject
     * @var ThumbnailService
     */
    protected $thumbnailService;

    /**
     * Build asset uri
     *
     * @param AssetInterface|AssetInterface[]|null $value
     * @param integer $width
     * @param integer $height
     * @param boolean $allowCropping
     * @return null|string
     */
    public function build($value, $width, $height, $allowCropping = true)
    {
        if ($value instanceof AssetInterface) {
            $thumbnailConfiguration = new ThumbnailConfiguration($width, $width, $height, $height, $allowCropping);
            $thumbnailData = $this->assetService->getThumbnailUriAndSizeForAsset($value, $thumbnailConfiguration);
            if ($thumbnailData === null) {
                return null;
            }
            return $thumbnailData['src'];
        }

        return null;
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
