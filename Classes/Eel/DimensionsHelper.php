<?php
declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Eel;

use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * DimensionsHelper
 */
class DimensionsHelper implements ProtectedContextAwareInterface
{
    /**
     * Resource publisher
     *
     * @Flow\Inject
     * @var DimensionsService
     */
    protected $dimensionsService;

    /**
     * Build hash from dimension values
     *
     * @param array<string, mixed> $dimensionsValues
     * @return string
     */
    public function hash(array $dimensionsValues)
    {
        return $this->dimensionsService->hash($dimensionsValues);
    }

    /**
     * @param NodeInterface $node
     * @return string|null
     */
    public function hashByNode(NodeInterface $node): ?string
    {
        return $this->dimensionsService->hashByNode($node);
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
