<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Eel;

use Medienreaktor\Meilisearch\Domain\Service\RequestService;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\Thumbnail;
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
     * @Flow\Inject
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
    * @Flow\Inject
    * @var RequestService
    */
    protected $requestService;

    /**
     * @var string
     * @Flow\InjectConfiguration(path="http.baseUri", package="Neos.Flow")
     */
    protected $baseUri;

    /**
     * Build asset uri
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

        // If no baseUri is set, we create async thumbnails
        $async = !$this->baseUri;
        $thumbnailConfiguration = new ThumbnailConfiguration($width, $width, $height, $height, $allowCropping, $allowUpScaling, $async, format: $format);

        if ($async) {
            $thumbnailImage = $this->thumbnailService->getThumbnail($value, $thumbnailConfiguration);
            if ($thumbnailImage instanceof Thumbnail) {
                $request = $this->requestService->createActionRequest();
                $this->uriBuilder->setRequest($request->getMainRequest());
                $uri = $this->uriBuilder
                        ->reset()
                        ->setCreateAbsoluteUri(false)
                        ->uriFor('thumbnail', ['thumbnail' => $thumbnailImage], 'Thumbnail', 'Neos.Media');
                return $uri ?: null;
            }
            return null;
        }

        $thumbnailData = $this->assetService->getThumbnailUriAndSizeForAsset($value, $thumbnailConfiguration);
        if ($thumbnailData === null) {
            return null;
        }
        return $thumbnailData['src'];
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
