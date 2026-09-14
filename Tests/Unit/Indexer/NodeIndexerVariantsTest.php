<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Indexer;

use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\LiveWorkspaceNodeIndexer;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\RecordingIndex;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\NodeAggregate\NodeAggregateIdentifier;
use Neos\ContentRepository\Domain\Service\Context;
use PHPUnit\Framework\TestCase;

/**
 * Every variant of a node aggregate is one document per dimension combination, and
 * indexing any one of them used to delete all of them while re-adding only its own
 * combination and the ones falling back to it: publishing German removed English.
 *
 * The site here has German, English, and Austrian English falling back to English.
 */
class NodeIndexerVariantsTest extends TestCase
{
    private const COMBINATIONS_FALLING_BACK_TO = [
        'de' => [['language' => ['de']]],
        'en' => [['language' => ['en']], ['language' => ['en_AT', 'en']]],
    ];

    private RecordingIndex $index;

    private LiveWorkspaceNodeIndexer $nodeIndexer;

    private DimensionsService $dimensionsService;

    protected function setUp(): void
    {
        $this->index = new RecordingIndex();
        $this->nodeIndexer = new LiveWorkspaceNodeIndexer();
        $this->dimensionsService = $this->createPartialMock(DimensionsService::class, ['getDimensionCombinationsForIndexing']);
        $this->dimensionsService->method('getDimensionCombinationsForIndexing')->willReturnCallback(
            static fn(NodeInterface $node): array => self::COMBINATIONS_FALLING_BACK_TO[$node->getDimensions()['language'][0]]
        );

        $this->inject('indexClient', $this->index);
        $this->inject('dimensionsService', $this->dimensionsService);
    }

    public function testIndexingOneVariantKeepsTheVariantsOfUnrelatedDimensions(): void
    {
        $this->nodeIndexer->visibleInLive = ['de' => true, 'en' => true, 'en_AT' => true];

        $this->nodeIndexer->indexNode($this->documentNode('de', true), 'live');
        $this->nodeIndexer->flush();

        self::assertSame(['addDocuments'], $this->index->calledMethods());
        self::assertSame([$this->documentId(['de'])], array_column($this->index->argumentOf('addDocuments'), 'id'));
    }

    public function testAVariantHiddenInLiveDropsOutTogetherWithItsFallbacks(): void
    {
        $this->nodeIndexer->visibleInLive = ['de' => true];

        $this->nodeIndexer->indexNode($this->documentNode('en', false), 'live');
        $this->nodeIndexer->flush();

        self::assertSame(['deleteDocuments'], $this->index->calledMethods());
        self::assertSame(
            [$this->documentId(['en']), $this->documentId(['en_AT', 'en'])],
            $this->index->argumentOf('deleteDocuments')
        );
    }

    /**
     * Realtime indexing also fires for edits in user workspaces, while the index only
     * ever holds live content.
     */
    public function testAVariantHiddenOnlyOutsideLiveStaysInTheIndex(): void
    {
        $this->nodeIndexer->visibleInLive = ['de' => true, 'en' => true, 'en_AT' => true];

        $this->nodeIndexer->indexNode($this->documentNode('en', false));
        $this->nodeIndexer->flush();

        self::assertSame(['addDocuments'], $this->index->calledMethods());
        self::assertCount(2, $this->index->argumentOf('addDocuments'));
    }

    public function testRemovingAVariantRebuildsEveryCombinationThatFellBackToIt(): void
    {
        // The Austrian variant exists on its own, so only English itself disappears.
        $this->nodeIndexer->visibleInLive = ['de' => true, 'en_AT' => true];

        $this->nodeIndexer->removeNode($this->documentNode('en', true));
        $this->nodeIndexer->flush();

        self::assertSame(['deleteDocuments', 'addDocuments'], $this->index->calledMethods());
        self::assertSame([$this->documentId(['en'])], $this->index->argumentOf('deleteDocuments'));
        self::assertSame([$this->documentId(['en_AT', 'en'])], array_column($this->index->argumentOf('addDocuments'), 'id'));
    }

    public function testRemovingContentRebuildsTheDocumentItBelongsTo(): void
    {
        $this->nodeIndexer->visibleInLive = ['de' => true, 'en' => true, 'en_AT' => true];
        $content = $this->node('content', 'en', false, true);
        $content->method('findParentNode')->willReturn($this->documentNode('en', true));

        $this->nodeIndexer->removeNode($content);
        $this->nodeIndexer->flush();

        self::assertSame(
            [$this->documentId(['en']), $this->documentId(['en_AT', 'en'])],
            array_column($this->index->argumentOf('addDocuments'), 'id')
        );
    }

    public function testADimensionlessSiteReplacesItsOnlyDocument(): void
    {
        $dimensionsService = $this->createPartialMock(DimensionsService::class, ['getDimensionCombinationsForIndexing']);
        $dimensionsService->method('getDimensionCombinationsForIndexing')->willReturn([[]]);
        $this->inject('dimensionsService', $dimensionsService);
        $this->nodeIndexer->visibleInLive = ['default' => true];

        $this->nodeIndexer->indexNode($this->documentNode('de', true), 'live');
        $this->nodeIndexer->flush();

        self::assertSame([[]], $this->nodeIndexer->extractedCombinations);
        self::assertSame(['document_default'], array_column($this->index->argumentOf('addDocuments'), 'id'));
    }

    /**
     * @return Node&\PHPUnit\Framework\MockObject\MockObject
     */
    private function documentNode(string $language, bool $visible): Node
    {
        return $this->node('document', $language, true, $visible);
    }

    /**
     * @return Node&\PHPUnit\Framework\MockObject\MockObject
     */
    private function node(string $identifier, string $language, bool $fulltextRoot, bool $visible): Node
    {
        $nodeType = $this->createMock(NodeType::class);
        $nodeType->method('hasConfiguration')->with('search')->willReturn($fulltextRoot);
        $nodeType->method('getConfiguration')->with('search')->willReturn(['fulltext' => ['isRoot' => $fulltextRoot]]);

        $node = $this->createMock(Node::class);
        $node->method('getNodeType')->willReturn($nodeType);
        $node->method('getNodeAggregateIdentifier')->willReturn(NodeAggregateIdentifier::fromString($identifier));
        $node->method('isVisible')->willReturn($visible);
        $node->method('getDimensions')->willReturn(['language' => [$language]]);

        $context = $this->createMock(Context::class);
        $context->method('getDimensions')->willReturn(['language' => [$language]]);
        $context->method('getTargetDimensions')->willReturn(['language' => $language]);
        $node->method('getContext')->willReturn($context);

        return $node;
    }

    /**
     * @param string[] $languages
     */
    private function documentId(array $languages): string
    {
        return 'document_' . $this->dimensionsService->hash(['language' => $languages]);
    }

    /**
     * @param mixed $value
     */
    private function inject(string $property, $value): void
    {
        $reflection = new \ReflectionProperty(LiveWorkspaceNodeIndexer::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($this->nodeIndexer, $value);
    }
}
