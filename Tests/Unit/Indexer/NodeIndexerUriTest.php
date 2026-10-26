<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Indexer;

use GuzzleHttp\Psr7\Uri;
use Medienreaktor\Meilisearch\Domain\Service\NodeLinkService;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\NodeIndexerWithNodeTypes;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeTags;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Timestamps;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Serializer;

/**
 * Any node type can be a fulltext root, but only documents have a route. Asking the
 * router for the URI of a content root (e.g. a single FAQ entry) threw and aborted
 * the whole index build. These cases pin which nodes get a URI and which get null.
 *
 * The node types are real, so inheritance from Neos.Neos:Document is what decides;
 * see NodeIndexerWithNodeTypes for why they are not looked up through the registry.
 */
class NodeIndexerUriTest extends TestCase
{
    private NodeTypeManager $nodeTypeManager;

    protected function setUp(): void
    {
        $this->nodeTypeManager = NodeTypeManager::createFromArrayConfiguration([
            'Neos.Neos:Document' => ['search' => ['fulltext' => ['isRoot' => true]]],
            'Neos.Neos:Content' => [],
            'Vendor.Site:Document.Page' => ['superTypes' => ['Neos.Neos:Document' => true]],
            'Vendor.Site:Content.FaqEntry' => [
                'superTypes' => ['Neos.Neos:Content' => true],
                'search' => ['fulltext' => ['isRoot' => true]],
            ],
        ]);
    }

    public function testADocumentGetsItsUriFromTheLinkService(): void
    {
        $node = $this->createNode('Vendor.Site:Document.Page');
        $uri = new Uri('https://example.com/page');

        $nodeLinkService = $this->createMock(NodeLinkService::class);
        $nodeLinkService->expects(self::once())->method('getNodeUri')->with($node)->willReturn($uri);

        self::assertSame($uri, $this->createNodeIndexer($nodeLinkService)->getNodeUri($node));
    }

    public function testAContentNodeConfiguredAsFulltextRootHasNoUri(): void
    {
        $node = $this->createNode('Vendor.Site:Content.FaqEntry');

        // Routing a content node throws NoMatchingRouteException, so it must not be asked.
        $nodeLinkService = $this->createMock(NodeLinkService::class);
        $nodeLinkService->expects(self::never())->method('getNodeUri');

        $nodeIndexer = $this->createNodeIndexer($nodeLinkService);

        // Guards the premise: the entry is indexed as a document of its own.
        self::assertTrue($nodeIndexer->isFulltextRoot($node));
        self::assertNull($nodeIndexer->getNodeUri($node));
    }

    public function testANodeOfAnUnknownTypeHasNoUri(): void
    {
        $nodeLinkService = $this->createMock(NodeLinkService::class);
        $nodeLinkService->expects(self::never())->method('getNodeUri');

        self::assertNull($this->createNodeIndexer($nodeLinkService)->getNodeUri($this->createNode('Vendor.Site:Removed')));
    }

    private function createNodeIndexer(NodeLinkService $nodeLinkService): NodeIndexerWithNodeTypes
    {
        return new NodeIndexerWithNodeTypes($this->nodeTypeManager, $nodeLinkService);
    }

    private function createNode(string $nodeTypeName): Node
    {
        return Node::create(
            ContentRepositoryId::fromString('default'),
            WorkspaceName::forLive(),
            DimensionSpacePoint::createWithoutDimensions(),
            NodeAggregateId::fromString('node'),
            OriginDimensionSpacePoint::createWithoutDimensions(),
            NodeAggregateClassification::CLASSIFICATION_REGULAR,
            NodeTypeName::fromString($nodeTypeName),
            new PropertyCollection(SerializedPropertyValues::createEmpty(), new PropertyConverter(new Serializer())),
            null,
            NodeTags::createEmpty(),
            Timestamps::create(new \DateTimeImmutable(), new \DateTimeImmutable(), null, null),
            VisibilityConstraints::createEmpty()
        );
    }
}
