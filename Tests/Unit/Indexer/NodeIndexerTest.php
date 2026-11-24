<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Indexer;

use GuzzleHttp\Psr7\Uri;
use Medienreaktor\Meilisearch\Domain\Service\NodeLinkService;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\DocumentExtractingNodeIndexer;
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
 * What a node variant becomes in the index: its id, its fulltext as the settings
 * allow it, its URI, and whether it is indexed at all. See
 * DocumentExtractingNodeIndexer for which part of extraction is given up front.
 */
class NodeIndexerTest extends TestCase
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

    /**
     * The id joins the aggregate and the dimension space point the node was read in,
     * so every variant is a document of its own.
     */
    public function testTheDocumentIsIdentifiedByItsVariant(): void
    {
        $dimensionSpacePoint = DimensionSpacePoint::fromArray(['language' => 'de']);
        $document = $this->indexer(['__identifier' => 'faq'])->extractDocument($this->createNode('Vendor.Site:Content.FaqEntry', $dimensionSpacePoint));

        self::assertNotNull($document);
        self::assertSame('faq_' . $dimensionSpacePoint->hash, $document['id']);
        self::assertSame('faq', $document['__identifier']);
    }

    public function testTheDocumentCarriesTheCollectedFulltext(): void
    {
        $document = $this->indexer([], ['h1' => 'Title', 'text' => 'Body'])->extractDocument($this->createNode('Vendor.Site:Content.FaqEntry'));

        self::assertNotNull($document);
        self::assertSame(['h1' => 'Title', 'text' => 'Body'], $document['__fulltext']);
    }

    public function testWithFulltextDisabledTheDocumentHasNoFulltext(): void
    {
        $document = $this->indexer([], ['text' => 'Body'], enableFulltext: false)->extractDocument($this->createNode('Vendor.Site:Content.FaqEntry'));

        self::assertNotNull($document);
        self::assertArrayNotHasKey('__fulltext', $document);
    }

    public function testADocumentNodeCarriesItsUri(): void
    {
        $nodeLinkService = $this->createMock(NodeLinkService::class);
        $nodeLinkService->method('getNodeUri')->willReturn(new Uri('https://example.com/page'));

        $document = $this->indexer([], nodeLinkService: $nodeLinkService)->extractDocument($this->createNode('Vendor.Site:Document.Page'));

        self::assertNotNull($document);
        self::assertSame('https://example.com/page', $document['__uri']);
    }

    public function testAContentNodeIsIndexedWithoutAUri(): void
    {
        $document = $this->indexer([])->extractDocument($this->createNode('Vendor.Site:Content.FaqEntry'));

        self::assertNotNull($document);
        self::assertArrayHasKey('__uri', $document);
        self::assertNull($document['__uri']);
    }

    /**
     * A node that lacks a needed attribute gets no document, which callers turn into
     * the removal of the one it had.
     */
    public function testANodeLackingANeededAttributeHasNoDocument(): void
    {
        $node = $this->createNode('Vendor.Site:Content.FaqEntry');

        self::assertNull($this->indexer(['category' => 'news'], neededAttributesForIndex: ['category', 'image'])->extractDocument($node));
        self::assertNull($this->indexer(['category' => ''], neededAttributesForIndex: ['category'])->extractDocument($node));
    }

    public function testANodeWithEveryNeededAttributeKeepsItsDocument(): void
    {
        $document = $this->indexer(['category' => 'news', 'image' => 'a.jpg'], neededAttributesForIndex: ['category', 'image'])
            ->extractDocument($this->createNode('Vendor.Site:Content.FaqEntry'));

        self::assertNotNull($document);
        self::assertSame('news', $document['category']);
    }

    /**
     * Meilisearch rejects documents beyond its payload limit, so overlong fulltext is
     * cut rather than failing the whole batch.
     */
    public function testFulltextBeyondTheLimitIsCut(): void
    {
        $document = $this->indexer([], ['text' => 'abcdefgh', 'h1' => 'abc'], maxFulltextBytes: 5)
            ->extractDocument($this->createNode('Vendor.Site:Content.FaqEntry'));

        self::assertNotNull($document);
        self::assertSame(['text' => 'abcde', 'h1' => 'abc'], $document['__fulltext']);
    }

    /**
     * The limit is in bytes, and a character is not split.
     */
    public function testMultibyteFulltextIsCutToTheByteLimit(): void
    {
        $document = $this->indexer([], ['text' => str_repeat('ä', 8)], maxFulltextBytes: 7)
            ->extractDocument($this->createNode('Vendor.Site:Content.FaqEntry'));

        self::assertNotNull($document);
        self::assertSame('äää', $document['__fulltext']['text']);
    }

    /**
     * Meilisearch reads coordinates from `_geo` only; a node type can only name a
     * property `__geo`.
     */
    public function testGeoCoordinatesMoveToMeilisearchsGeoAttribute(): void
    {
        $document = $this->indexer(['__geo' => ['lat' => 48.2, 'lng' => 16.4]])->extractDocument($this->createNode('Vendor.Site:Content.FaqEntry'));

        self::assertNotNull($document);
        self::assertSame(['lat' => 48.2, 'lng' => 16.4], $document['_geo']);
        self::assertArrayNotHasKey('__geo', $document);
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, string> $fulltext
     * @param list<string> $neededAttributesForIndex
     */
    private function indexer(
        array $properties,
        array $fulltext = [],
        bool $enableFulltext = true,
        array $neededAttributesForIndex = [],
        int $maxFulltextBytes = 500000,
        ?NodeLinkService $nodeLinkService = null
    ): DocumentExtractingNodeIndexer {
        return new DocumentExtractingNodeIndexer(
            $this->nodeTypeManager,
            $nodeLinkService ?? $this->createStub(NodeLinkService::class),
            $properties,
            $fulltext,
            $enableFulltext,
            $neededAttributesForIndex,
            $maxFulltextBytes
        );
    }

    private function createNode(string $nodeTypeName, ?DimensionSpacePoint $dimensionSpacePoint = null): Node
    {
        $dimensionSpacePoint ??= DimensionSpacePoint::createWithoutDimensions();

        return Node::create(
            ContentRepositoryId::fromString('default'),
            WorkspaceName::forLive(),
            $dimensionSpacePoint,
            NodeAggregateId::fromString(str_contains($nodeTypeName, 'Faq') ? 'faq' : 'page'),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($dimensionSpacePoint),
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
