<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Indexer;

use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\ConstrainedPresetSource;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\DetachedNode;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\LiveWorkspaceNodeIndexer;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\RecordingIndex;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
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
    /**
     * @var array<string, array{presets: array<string, array{values: array<int, string>}>}>
     */
    private const PRESETS = [
        'language' => [
            'presets' => [
                'de' => ['values' => ['de']],
                'en' => ['values' => ['en']],
                'en_AT' => ['values' => ['en_AT', 'en']],
            ],
        ],
    ];

    private RecordingIndex $index;

    private LiveWorkspaceNodeIndexer $nodeIndexer;

    private DimensionsService $dimensionsService;

    protected function setUp(): void
    {
        $this->index = new RecordingIndex();
        $this->nodeIndexer = new LiveWorkspaceNodeIndexer();
        $this->dimensionsService = $this->dimensionsServiceFor(self::PRESETS);

        $this->seed($this->nodeIndexer, 'indexClient', $this->index);
        $this->seed($this->nodeIndexer, 'dimensionsService', $this->dimensionsService);
    }

    public function testIndexingOneVariantKeepsTheVariantsOfUnrelatedDimensions(): void
    {
        $this->nodeIndexer->visibleInLive = ['de' => true, 'en' => true, 'en_AT' => true];

        $this->nodeIndexer->indexNode($this->document('de'), 'live');
        $this->nodeIndexer->flush();

        self::assertSame(['addDocuments'], $this->index->calledMethods());
        self::assertSame([$this->documentId(['de'])], array_column($this->index->argumentOf('addDocuments'), 'id'));
    }

    public function testAVariantHiddenInLiveDropsOutTogetherWithItsFallbacks(): void
    {
        $this->nodeIndexer->visibleInLive = ['de' => true];

        $this->nodeIndexer->indexNode($this->document('en', false), 'live');
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

        $this->nodeIndexer->indexNode($this->document('en', false));
        $this->nodeIndexer->flush();

        self::assertSame(['addDocuments'], $this->index->calledMethods());
        self::assertCount(2, $this->index->argumentOf('addDocuments'));
    }

    public function testRemovingAVariantRebuildsEveryCombinationThatFellBackToIt(): void
    {
        // The Austrian variant exists on its own, so only English itself disappears.
        $this->nodeIndexer->visibleInLive = ['de' => true, 'en_AT' => true];

        $this->nodeIndexer->removeNode($this->document('en'));
        $this->nodeIndexer->flush();

        self::assertSame(['deleteDocuments', 'addDocuments'], $this->index->calledMethods());
        self::assertSame([$this->documentId(['en'])], $this->index->argumentOf('deleteDocuments'));
        self::assertSame([$this->documentId(['en_AT', 'en'])], array_column($this->index->argumentOf('addDocuments'), 'id'));
    }

    public function testRemovingContentRebuildsTheDocumentItBelongsTo(): void
    {
        $this->nodeIndexer->visibleInLive = ['de' => true, 'en' => true, 'en_AT' => true];
        $content = new DetachedNode('content', ['language' => ['en']], false, true, $this->document('en'));

        $this->nodeIndexer->removeNode($content);
        $this->nodeIndexer->flush();

        self::assertSame(
            [$this->documentId(['en']), $this->documentId(['en_AT', 'en'])],
            array_column($this->index->argumentOf('addDocuments'), 'id')
        );
    }

    public function testADimensionlessSiteReplacesItsOnlyDocument(): void
    {
        $this->seed($this->nodeIndexer, 'dimensionsService', $this->dimensionsServiceFor([]));
        $this->nodeIndexer->visibleInLive = ['default' => true];

        $this->nodeIndexer->indexNode(new DetachedNode('document', [], true), 'live');
        $this->nodeIndexer->flush();

        self::assertSame([[]], $this->nodeIndexer->extractedCombinations);
        self::assertSame(['document_default'], array_column($this->index->argumentOf('addDocuments'), 'id'));
    }

    private function document(string $language, bool $visible = true): DetachedNode
    {
        return new DetachedNode('document', ['language' => [$language]], true, $visible);
    }

    /**
     * @param string[] $languages
     */
    private function documentId(array $languages): string
    {
        return 'document_' . $this->dimensionsService->hash(['language' => $languages]);
    }

    /**
     * @param array<string, array{presets: array<string, array{values: array<int, string>}>}> $presets
     */
    private function dimensionsServiceFor(array $presets): DimensionsService
    {
        $combinator = new ContentDimensionCombinator();
        $dimensionsService = new DimensionsService();
        $this->seed($combinator, 'contentDimensionPresetSource', new ConstrainedPresetSource($presets));
        $this->seed($dimensionsService, 'contentDimensionCombinator', $combinator);

        return $dimensionsService;
    }

    /**
     * @param mixed $value
     */
    private function seed(object $object, string $propertyName, $value): void
    {
        $property = (new \ReflectionObject($object))->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
