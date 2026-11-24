<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Fixtures;

use Medienreaktor\Meilisearch\Domain\Service\NodeLinkService;
use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;

/**
 * Opens extractDocument() to tests, with the properties and fulltext a node yields
 * given up front.
 *
 * Reading properties and collecting fulltext from child nodes goes through the
 * content repository registry, which is final and cannot be stood in for. What
 * extractDocument() does with their result - the settings it applies and the shape
 * of the document - does not depend on where they came from.
 */
final class DocumentExtractingNodeIndexer extends NodeIndexer
{
    /**
     * @param array<string, mixed> $properties
     * @param array<string, string> $fulltext
     * @param list<string> $neededAttributesForIndex
     */
    public function __construct(
        private readonly NodeTypeManager $nodeTypeManager,
        NodeLinkService $nodeLinkService,
        private readonly array $properties,
        private readonly array $fulltext = [],
        bool $enableFulltext = true,
        array $neededAttributesForIndex = [],
        int $maxFulltextBytes = 500000
    ) {
        $this->nodeLinkService = $nodeLinkService;
        $this->enableFulltext = $enableFulltext;
        $this->neededAttributesForIndex = $neededAttributesForIndex;
        $this->maxFulltextBytes = $maxFulltextBytes;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function extractDocument(Node $node): ?array
    {
        return parent::extractDocument($node);
    }

    /**
     * @param array<string, mixed> $fulltextData
     * @return array<string, mixed>
     */
    protected function extractPropertiesAndFulltext(?Node $node, array &$fulltextData, ?\Closure $nonIndexedPropertyErrorHandler = null): array
    {
        $fulltextData = $this->fulltext;
        return $this->properties;
    }

    protected function getNodeType(Node $node): ?NodeType
    {
        return $this->nodeTypeManager->getNodeType($node->nodeTypeName);
    }
}
