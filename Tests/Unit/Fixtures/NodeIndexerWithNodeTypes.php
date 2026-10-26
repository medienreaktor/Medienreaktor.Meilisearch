<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Fixtures;

use Medienreaktor\Meilisearch\Domain\Service\NodeLinkService;
use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Psr\Http\Message\UriInterface;

/**
 * Looks node types up in the given NodeTypeManager and opens the URI decision to tests.
 *
 * The indexer reaches node types through the content repository registry, and the
 * registry, the content repository and its NodeTypeManager are all final. Swapping
 * the lookup here keeps real node types and their inheritance in the test without
 * widening anything in the indexer itself.
 */
final class NodeIndexerWithNodeTypes extends NodeIndexer
{
    public function __construct(
        private readonly NodeTypeManager $nodeTypeManager,
        NodeLinkService $nodeLinkService
    ) {
        $this->nodeLinkService = $nodeLinkService;
    }

    public function getNodeUri(Node $node): ?UriInterface
    {
        return parent::getNodeUri($node);
    }

    public function isFulltextRoot(Node $node): bool
    {
        return parent::isFulltextRoot($node);
    }

    protected function getNodeType(Node $node): ?NodeType
    {
        return $this->nodeTypeManager->getNodeType($node->nodeTypeName);
    }
}
