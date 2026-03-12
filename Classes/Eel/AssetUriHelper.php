<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Eel;

use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
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
     * @param string $format
     * @return null|string
     */
    public function build($value, $width, $height, $allowCropping = true, $allowUpScaling = true, $format = null)
    {
        if (!$value instanceof AssetInterface) {
            return null;
        }

        $identifier = $this->persistenceManager->getIdentifierByObject($value);
        if ($identifier === null) {
            return null;
        }

        $params = [];
        $params['width'] = (int)$width;
        $params['height'] = (int)$height;
        if ($allowCropping) {
            $params['crop'] = 1;
        }
        if ($format !== null) {
            $params['format'] = $format;
        }

        return '/search/thumbnail/' . $identifier . '?' . http_build_query($params);
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
