<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Eel;

use Medienreaktor\Meilisearch\Domain\Service\RequestService;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\AssetInterface;

/**
 * AssetUriHelper
 *
 * Generates stable thumbnail proxy URIs for Meilisearch indexing. The URI
 * encodes the permanent Asset identifier and desired thumbnail dimensions,
 * so a ThumbnailController can resolve them on-the-fly. This survives
 * media:clearthumbnails, resource cleanup, and thumbnail regeneration.
 */
class AssetUriHelper implements ProtectedContextAwareInterface
{
    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var RequestService
     */
    protected $requestService;

    /**
     * Build a proxy URI that encodes the asset identifier and thumbnail params.
     *
     * The returned URI is resolved by Medienreaktor\Meilisearch\Controller\ThumbnailController,
     * which looks up the asset, generates the thumbnail if needed, and redirects
     * to the current persistent resource URI.
     *
     * @param AssetInterface|AssetInterface[]|null $value
     * @param integer $width
     * @param integer $height
     * @param boolean $allowCropping
     * @param boolean $allowUpScaling
     * @param string|null $format
     * @return string|null
     */
    public function build(
        $value,
        $width,
        $height,
        $allowCropping = true,
        $allowUpScaling = true,
        $format = null
    ): ?string {
        if (!$value instanceof AssetInterface) {
            return null;
        }

        // If no baseUri is set, we create async thumbnails
        $async = !$this->baseUri;
        $thumbnailConfiguration = new ThumbnailConfiguration($width, $width, $height, $height, $allowCropping, $allowUpScaling, $async, null, $format);

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

        $arguments = [
            'asset' => $identifier,
            'width' => (int)$width,
            'height' => (int)$height,
        ];

        if ($allowCropping) {
            $arguments['crop'] = 1;
        }
        if ($format !== null) {
            $arguments['format'] = $format;
        }

        $actionRequest = $this->requestService->createActionRequest();
        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($actionRequest);
        $uriBuilder->setCreateAbsoluteUri(false);

        return $uriBuilder->uriFor(
            'thumbnail',
            $arguments,
            'Thumbnail',
            'Medienreaktor.Meilisearch'
        ) ?: null;
    }

    /**
     * All methods are considered safe
     *
     * @param string $methodName
     * @return boolean
     */
    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }
}
