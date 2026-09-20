<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Domain\Service;

use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\ReconciliationNode;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\RecordingContextFactory;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Service\Context;
use PHPUnit\Framework\TestCase;

// phpcs:disable PSR1.Files.SideEffects -- The test fixture is not part of the package's production autoloader.
require_once __DIR__ . '/Fixtures/TestableScheduledVisibilityReconciliationService.php';
// phpcs:enable PSR1.Files.SideEffects

class ScheduledVisibilityReconciliationServiceTest extends TestCase
{
    /**
     * @var RecordingContextFactory
     */
    private $contextFactory;

    /**
     * @var DimensionsService
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

    protected function setUp(): void
    {
        $this->contextFactory = new RecordingContextFactory();
        $hashResolver = fn(NodeInterface $node): string => $this->dimensionHashes[spl_object_id($node)]
            ?? 'hash-' . $node->getIdentifier();
        $this->dimensionsService = new class ($hashResolver) extends DimensionsService {
            private \Closure $hashResolver;

            public function __construct(\Closure $hashResolver)
            {
                $this->hashResolver = $hashResolver;
            }

            public function getAllCombinations(): array
            {
                return [['language' => ['de']]];
            }

            public function hashByNode(NodeInterface $node): ?string
            {
                return ($this->hashResolver)($node);
            }
        };

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
        $this->contextFactory->contexts = [$maintenanceContext, $visibleContext];

        $operations = $this->service->collectOperations(
            $this->createNodeData('/sites/example/document/main/content'),
            new \DateTimeImmutable('2026-07-16T12:00:00+02:00')
        );

        self::assertCount(1, $operations);
        self::assertCount(2, $this->contextFactory->configurations);
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
        $this->contextFactory->contexts = [$maintenanceContext, $visibleContext];

        $operations = $this->service->collectOperations(
            $this->createNodeData('/sites/example/scheduled'),
            new \DateTimeImmutable('2026-07-16T12:00:00+02:00')
        );

        self::assertCount(2, $operations);
        self::assertCount(2, $this->contextFactory->configurations);
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
        $this->contextFactory->contexts = [$maintenanceContext, $visibleContext];

        $operations = $this->service->collectOperations(
            $this->createNodeData('/sites/example/scheduled'),
            new \DateTimeImmutable('2026-07-16T12:00:00+02:00')
        );

        self::assertCount(2, $operations);
        self::assertCount(2, $this->contextFactory->configurations);
        self::assertSame('remove', $operations['scheduled-document:hash-scheduled-document']['action']);
        self::assertSame('remove', $operations['child-document:hash-child-document']['action']);
    }

    public function testHiddenDocumentRetainsItsTargetCombination(): void
    {
        $hiddenDocument = $this->createNode('document', '/sites/example/document', false, true);
        $this->dimensionHashes[spl_object_id($hiddenDocument)] = 'de-hash';

        $maintenanceContext = $this->createContext([
            '/sites/example/document' => $hiddenDocument,
        ]);
        $visibleContext = $this->createContext([]);
        $this->contextFactory->contexts = [$maintenanceContext, $visibleContext];

        $operations = $this->service->collectOperations(
            $this->createNodeData('/sites/example/document'),
            new \DateTimeImmutable('2026-07-16T12:00:00+02:00')
        );

        self::assertCount(1, $operations);
        self::assertCount(2, $this->contextFactory->configurations);
        self::assertSame('remove', $operations['document:de-hash']['action']);
        self::assertSame($hiddenDocument, $operations['document:de-hash']['node']);
        self::assertSame(['language' => ['de']], $operations['document:de-hash']['combination']);
    }

    /**
     * @return ReconciliationNode
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
        return new ReconciliationNode($identifier, $path, $visible, $fulltextRoot, $parent, $parentCallback, $children);
    }

    private function createNodeData(string $path): NodeData
    {
        return new NodeData($path, new Workspace('live'), null, ['language' => ['de']]);
    }

    /**
     * @param array<string, NodeInterface> $nodesByPath
     */
    private function createContext(array $nodesByPath): Context
    {
        return new class ($nodesByPath) extends Context {
            /** @var array<string, NodeInterface> */
            private array $nodesByPath;

            /** @param array<string, NodeInterface> $nodesByPath */
            public function __construct(array $nodesByPath)
            {
                $this->nodesByPath = $nodesByPath;
            }

            public function getNode($path): ?NodeInterface
            {
                return $this->nodesByPath[$path] ?? null;
            }
        };
    }
}
