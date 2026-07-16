<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Domain\Service;

use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

// phpcs:disable PSR1.Files.SideEffects -- The test fixture is not part of the package's production autoloader.
require_once __DIR__ . '/Fixtures/TestableScheduledVisibilityReconciliationService.php';
// phpcs:enable PSR1.Files.SideEffects

class ScheduledVisibilityReconciliationServiceTest extends TestCase
{
    /**
     * @var ContextFactoryInterface&MockObject
     */
    private $contextFactory;

    /**
     * @var DimensionsService&MockObject
     */
    private $dimensionsService;

    /**
     * @var TestableScheduledVisibilityReconciliationService
     */
    private $service;

    /**
     * @var array<int, string>
     */
    private $dimensionHashes = [];

    /**
     * @var array<int, array<string, string[]>>
     */
    private $dimensionCombinations = [];

    protected function setUp(): void
    {
        $this->contextFactory = $this->createMock(ContextFactoryInterface::class);
        $this->dimensionsService = $this->createMock(DimensionsService::class);
        $this->dimensionsService->method('hashByNode')->willReturnCallback(
            fn(NodeInterface $node): string => $this->dimensionHashes[spl_object_id($node)]
                ?? 'hash-' . $node->getIdentifier()
        );
        $this->dimensionsService->method('getDimensionCombinationsForIndexing')->willReturnCallback(
            fn(): array => $this->dimensionCombinations
        );

        $this->service = new TestableScheduledVisibilityReconciliationService();
        $this->service->injectContextFactory($this->contextFactory);
        $this->service->injectDimensionsService($this->dimensionsService);
    }

    public function testHiddenContentReindexesItsVisibleFulltextRoot(): void
    {
        $document = $this->createNode('document', '/sites/example/document', true, true);
        $content = $this->createNode('content', '/sites/example/document/main/content', false, false, $document);

        $maintenanceContext = $this->createContext([
            '/sites/example/document/main/content' => $content,
        ]);
        $visibleContext = $this->createContext([
            '/sites/example/document' => $document,
        ]);
        $this->contextFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($maintenanceContext, $visibleContext);

        $operations = $this->service->collectOperations(
            $this->createNodeData('/sites/example/document/main/content'),
            new \DateTimeImmutable('2026-07-16T12:00:00+02:00')
        );

        self::assertCount(1, $operations);
        self::assertSame('index', $operations['document:hash-document']['action']);
        self::assertSame($document, $operations['document:hash-document']['node']);
    }

    public function testVisibleDocumentIndexesItsOwnAndDescendantFulltextRoots(): void
    {
        $scheduledDocument = null;
        $childDocument = $this->createNode(
            'child-document',
            '/sites/example/scheduled/child',
            true,
            true,
            null,
            static function () use (&$scheduledDocument): NodeInterface {
                self::assertInstanceOf(NodeInterface::class, $scheduledDocument);
                return $scheduledDocument;
            }
        );
        $scheduledDocument = $this->createNode(
            'scheduled-document',
            '/sites/example/scheduled',
            true,
            true,
            null,
            null,
            [$childDocument]
        );

        $maintenanceContext = $this->createContext([
            '/sites/example/scheduled' => $scheduledDocument,
        ]);
        $visibleContext = $this->createContext([
            '/sites/example/scheduled' => $scheduledDocument,
            '/sites/example/scheduled/child' => $childDocument,
        ]);
        $this->contextFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($maintenanceContext, $visibleContext);

        $operations = $this->service->collectOperations(
            $this->createNodeData('/sites/example/scheduled'),
            new \DateTimeImmutable('2026-07-16T12:00:00+02:00')
        );

        self::assertCount(2, $operations);
        self::assertSame('index', $operations['scheduled-document:hash-scheduled-document']['action']);
        self::assertSame('index', $operations['child-document:hash-child-document']['action']);
    }

    public function testHiddenDocumentRemovesItsOwnAndDescendantFulltextRoots(): void
    {
        $scheduledDocument = null;
        $childDocument = $this->createNode(
            'child-document',
            '/sites/example/scheduled/child',
            true,
            true,
            null,
            static function () use (&$scheduledDocument): NodeInterface {
                self::assertInstanceOf(NodeInterface::class, $scheduledDocument);
                return $scheduledDocument;
            }
        );
        $scheduledDocument = $this->createNode(
            'scheduled-document',
            '/sites/example/scheduled',
            false,
            true,
            null,
            null,
            [$childDocument]
        );

        $maintenanceContext = $this->createContext([
            '/sites/example/scheduled' => $scheduledDocument,
        ]);
        $visibleContext = $this->createContext([]);
        $this->contextFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($maintenanceContext, $visibleContext);

        $operations = $this->service->collectOperations(
            $this->createNodeData('/sites/example/scheduled'),
            new \DateTimeImmutable('2026-07-16T12:00:00+02:00')
        );

        self::assertCount(2, $operations);
        self::assertSame('remove', $operations['scheduled-document:hash-scheduled-document']['action']);
        self::assertSame('remove', $operations['child-document:hash-child-document']['action']);
    }

    public function testHiddenDocumentRemovesEveryTargetDimensionUsingFallback(): void
    {
        $hiddenDocument = $this->createNode('document', '/sites/example/document', false, true);
        $germanVariant = $this->createNode('document', '/sites/example/document', false, true);
        $englishFallbackVariant = $this->createNode('document', '/sites/example/document', false, true);
        $this->dimensionHashes[spl_object_id($germanVariant)] = 'de-hash';
        $this->dimensionHashes[spl_object_id($englishFallbackVariant)] = 'en-hash';
        $this->dimensionCombinations = [
            ['language' => ['de']],
            ['language' => ['en']],
        ];

        $maintenanceContext = $this->createContext([
            '/sites/example/document' => $hiddenDocument,
        ]);
        $visibleContext = $this->createContext([]);
        $germanContext = $this->createContext([], ['document' => $germanVariant]);
        $englishContext = $this->createContext([], ['document' => $englishFallbackVariant]);
        $this->contextFactory->expects(self::exactly(4))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $maintenanceContext,
                $visibleContext,
                $germanContext,
                $englishContext
            );

        $operations = $this->service->collectOperations(
            $this->createNodeData('/sites/example/document'),
            new \DateTimeImmutable('2026-07-16T12:00:00+02:00')
        );

        self::assertCount(2, $operations);
        self::assertSame('remove', $operations['document:de-hash']['action']);
        self::assertSame($germanVariant, $operations['document:de-hash']['node']);
        self::assertSame('remove', $operations['document:en-hash']['action']);
        self::assertSame($englishFallbackVariant, $operations['document:en-hash']['node']);
    }

    /**
     * @return Node&MockObject
     * @param NodeInterface[] $children
     */
    private function createNode(
        string $identifier,
        string $path,
        bool $visible,
        bool $fulltextRoot,
        ?NodeInterface $parent = null,
        ?callable $parentCallback = null,
        array $children = []
    ): Node {
        $node = $this->createMock(Node::class);
        $node->method('getIdentifier')->willReturn($identifier);
        $node->method('getPath')->willReturn($path);
        $node->method('isRemoved')->willReturn(false);
        $node->method('isVisible')->willReturn($visible);
        $node->method('isAccessible')->willReturn(true);
        $node->method('getChildNodes')->willReturn($children);

        $nodeType = $this->createMock(NodeType::class);
        $nodeType->method('getConfiguration')->with('search')->willReturn(
            $fulltextRoot ? ['fulltext' => ['isRoot' => true]] : []
        );
        $node->method('getNodeType')->willReturn($nodeType);

        if ($parentCallback !== null) {
            $node->method('getParent')->willReturnCallback($parentCallback);
        } elseif ($parent instanceof NodeInterface) {
            $node->method('getParent')->willReturn($parent);
        } else {
            $node->method('getParent')->willReturn(null);
        }

        return $node;
    }

    private function createNodeData(string $path): NodeData
    {
        $nodeData = $this->createMock(NodeData::class);
        $nodeData->method('getPath')->willReturn($path);
        $nodeData->method('getDimensionValues')->willReturn(['language' => ['de']]);
        return $nodeData;
    }

    /**
     * @param array<string, NodeInterface> $nodesByPath
     * @param array<string, NodeInterface> $nodesByIdentifier
     */
    private function createContext(array $nodesByPath, array $nodesByIdentifier = []): Context
    {
        $context = $this->createMock(Context::class);
        $context->method('getNode')->willReturnCallback(
            static fn(string $path): ?NodeInterface => $nodesByPath[$path] ?? null
        );
        $context->method('getNodeByIdentifier')->willReturnCallback(
            static fn(string $identifier): ?NodeInterface => $nodesByIdentifier[$identifier] ?? null
        );
        return $context;
    }
}
