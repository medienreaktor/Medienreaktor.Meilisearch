<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Functional;

use Medienreaktor\Meilisearch\Domain\Service\DimensionsService;
use Medienreaktor\Meilisearch\Domain\Service\NodeLinkService;
use Medienreaktor\Meilisearch\Domain\Service\RuntimeIndexingService;
use Medienreaktor\Meilisearch\Domain\Service\ScheduledVisibilityReconciliationService;
use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\RecordingIndex;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Tests\FunctionalTestCase;

class RuntimeIndexingTest extends FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;
    protected $testableSecurityEnabled = true;

    protected ContextFactoryInterface $contexts;
    protected Workspace $live;
    protected Workspace $draft;
    protected NodeIndexer $indexer;
    protected RecordingIndex $index;
    protected RuntimeIndexingService $repairs;
    protected NodeTypeManager $types;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager->get(\Neos\Flow\Security\Authorization\TestingPrivilegeManager::class)->setOverrideDecision(true);
        $this->contexts = $this->objectManager->get(ContextFactoryInterface::class);
        $this->types = $this->objectManager->get(NodeTypeManager::class);
        $this->indexer = $this->objectManager->get(NodeIndexer::class);
        $this->index = new RecordingIndex();
        $this->inject($this->indexer, 'indexClient', $this->index);
        $this->inject($this->indexer, 'neededAttributesForIndex', []);
        $links = new class extends NodeLinkService {
            public function getNodeUri(NodeInterface $node, ?Context $context = null): ?string
            {
                return 'https://example.test' . $node->getPath();
            }
        };
        $this->inject($this->indexer, 'nodeLinkService', $links);
        $combinator = new class extends ContentDimensionCombinator {
            public function getAllAllowedCombinations(): array
            {
                return [['language' => ['en']], ['language' => ['en_AU', 'en']], ['language' => ['de']]];
            }
        };
        $dimensions = $this->objectManager->get(DimensionsService::class);
        $this->inject($dimensions, 'contentDimensionCombinator', $combinator);
        $this->inject($dimensions, 'dimensionCombinationsForIndexing', []);
        $this->repairs = $this->objectManager->get(RuntimeIndexingService::class);
        $this->inject($this->repairs, 'publishingStates', []);
        $this->inject($this->repairs, 'operations', []);
        $this->inject($this->repairs, 'subtrees', []);
        $this->configureIndexer();
        $this->live = new Workspace('live');
        $this->draft = new Workspace('user-test', $this->live);
        $workspaces = $this->objectManager->get(WorkspaceRepository::class);
        $workspaces->add($this->live);
        $workspaces->add($this->draft);
        $this->persistenceManager->persistAll();
    }

    protected function configureIndexer(): void
    {
    }

    protected function persistChanges(): void
    {
        $this->persistenceManager->persistAll();
        $this->executeDeferredJobs();
    }

    protected function executeDeferredJobs(): void
    {
    }

    public function testPublishedContentMoveRepairsBothRoots(): void
    {
        $a = $this->document('a');
        $b = $this->document('b');
        $content = $a->createNode('text', $this->types->getNodeType('Medienreaktor.Meilisearch.Testing:Content'));
        $content->setProperty('text', 'Unique moved text');
        $this->persistChanges();
        $this->index->calls = [];
        $draft = $this->context('user-test');
        $moving = $draft->getNodeByIdentifier($content->getIdentifier());
        $moving->moveInto($draft->getNodeByIdentifier($b->getIdentifier()));
        $this->draft->publishNode($moving, $this->live);
        $this->persistChanges();
        $documents = $this->documentsWritten();
        self::assertStringNotContainsString('Unique moved text', json_encode($documents[$a->getIdentifier()]));
        self::assertStringContainsString('Unique moved text', json_encode($documents[$b->getIdentifier()]));
    }

    public function testParentHideAndUnhideRepairsChildrenWithoutChangingTheirFlags(): void
    {
        $parent = $this->document('parent');
        $child = $parent->createNode('child', $this->types->getNodeType('Medienreaktor.Meilisearch.Testing:Document'));
        $grandchild = $child->createNode('grandchild', $this->types->getNodeType('Medienreaktor.Meilisearch.Testing:Document'));
        $independentlyHidden = $child->createNode('hidden-child', $this->types->getNodeType('Medienreaktor.Meilisearch.Testing:Document'));
        $independentlyHidden->setHidden(true);
        $this->persistChanges();
        $this->index->calls = [];
        $draftParent = $this->context('user-test')->getNodeByIdentifier($parent->getIdentifier());
        $draftParent->setHidden(true);
        $this->draft->publishNode($draftParent, $this->live);
        $this->persistChanges();
        self::assertNotEmpty($this->deletedDocumentsFor($child->getIdentifier()));
        self::assertNotEmpty($this->deletedDocumentsFor($grandchild->getIdentifier()));
        self::assertArrayNotHasKey($child->getIdentifier(), $this->documentsWritten());
        self::assertFalse($child->isHidden());
        $this->index->calls = [];
        $child->setProperty('title', 'Edited under hidden parent');
        $this->persistChanges();
        self::assertArrayNotHasKey($child->getIdentifier(), $this->documentsWritten());
        $this->index->calls = [];
        $draftParent->setHidden(false);
        $this->draft->publishNode($draftParent, $this->live);
        $this->persistChanges();
        self::assertArrayHasKey($child->getIdentifier(), $this->documentsWritten());
        self::assertArrayHasKey($grandchild->getIdentifier(), $this->documentsWritten());
        self::assertArrayNotHasKey($independentlyHidden->getIdentifier(), $this->documentsWritten());
        self::assertTrue($independentlyHidden->isHidden());
    }

    public function testDocumentDemotionDeletesItsPreviousDocument(): void
    {
        $parent = $this->document('parent');
        $child = $parent->createNode('child', $this->types->getNodeType('Medienreaktor.Meilisearch.Testing:Document'));
        $this->persistChanges();
        $this->index->calls = [];
        $draftChild = $this->context('user-test')->getNodeByIdentifier($child->getIdentifier());
        $draftChild->setNodeType($this->types->getNodeType('Medienreaktor.Meilisearch.Testing:ExcludedDocument'));
        $this->draft->publishNode($draftChild, $this->live);
        $this->persistChanges();
        self::assertNotEmpty($this->deletedDocumentsFor($child->getIdentifier()));
        self::assertArrayNotHasKey($child->getIdentifier(), $this->documentsWritten());
        self::assertArrayHasKey($parent->getIdentifier(), $this->documentsWritten());
        $this->index->calls = [];
        $draftChild->setNodeType($this->types->getNodeType('Medienreaktor.Meilisearch.Testing:Document'));
        $this->draft->publishNode($draftChild, $this->live);
        $this->persistChanges();
        self::assertArrayHasKey($child->getIdentifier(), $this->documentsWritten());
        self::assertArrayHasKey($parent->getIdentifier(), $this->documentsWritten());
    }

    public function testLiveMoveUpdatesIndependentVariantsAndDescendants(): void
    {
        $target = $this->document('target');
        $moving = $this->document('moving');
        $child = $moving->createNode('child', $this->types->getNodeType('Medienreaktor.Meilisearch.Testing:Document'));
        foreach (['de', 'en_AU'] as $language) {
            $context = $this->contexts->create(['workspaceName' => 'live', 'dimensions' => ['language' => [$language]], 'invisibleContentShown' => true]);
            $target->createVariantForContext($context);
            $moving->createVariantForContext($context);
            $child->createVariantForContext($context);
        }
        $this->persistChanges();
        $this->index->calls = [];
        $moving->moveInto($target);
        $this->persistChanges();
        foreach ([$moving, $child] as $node) {
            $variants = $this->variantsWritten($node->getIdentifier());
            self::assertCount(3, $variants);
            foreach ($variants as $variant) {
                self::assertStringStartsWith('/target/moving', $variant['__path']);
                self::assertStringContainsString('/target/moving', $variant['__uri']);
                self::assertContains('/target', $variant['__parentPath']);
            }
        }
    }

    public function testDocumentMovePublishRepairsInternallyMovedDescendantRoots(): void
    {
        $target = $this->document('target');
        $moving = $this->document('moving');
        $child = $moving->createNode('child', $this->types->getNodeType('Medienreaktor.Meilisearch.Testing:Document'));
        $this->persistChanges();
        $this->index->calls = [];
        $draft = $this->context('user-test');
        $draftMoving = $draft->getNodeByIdentifier($moving->getIdentifier());
        $draftMoving->moveInto($draft->getNodeByIdentifier($target->getIdentifier()));
        $this->draft->publishNode($draftMoving, $this->live);
        $this->persistChanges();
        $variants = $this->variantsWritten($child->getIdentifier());
        self::assertCount(2, $variants);
        foreach ($variants as $variant) {
            self::assertSame('/target/moving/child', $variant['__path']);
            self::assertSame('https://example.test/target/moving/child', $variant['__uri']);
        }
    }

    public function testScheduledGenericParentRepairsFallbackOnlyChild(): void
    {
        $parent = $this->document('scheduled');
        $specific = $this->contexts->create(['workspaceName' => 'live', 'dimensions' => ['language' => ['en_AU', 'en']], 'invisibleContentShown' => true]);
        $child = $specific->getNodeByIdentifier($parent->getIdentifier())->createNode('specific-child', $this->types->getNodeType('Medienreaktor.Meilisearch.Testing:Document'));
        $this->persistChanges();
        $parent->setHiddenAfterDateTime(new \DateTime('yesterday'));
        $this->persistChanges();
        $this->index->calls = [];
        $this->objectManager->get(ScheduledVisibilityReconciliationService::class)->reconcile(new \DateTimeImmutable('-2 days'), new \DateTimeImmutable(), true);
        $this->executeDeferredJobs();
        self::assertNotEmpty($this->deletedDocumentsFor($child->getIdentifier()));
        self::assertArrayNotHasKey($child->getIdentifier(), $this->documentsWritten());
        $parent->setHiddenAfterDateTime(null);
        $parent->setHiddenBeforeDateTime(new \DateTime('yesterday'));
        $this->persistChanges();
        $this->index->calls = [];
        $this->objectManager->get(ScheduledVisibilityReconciliationService::class)->reconcile(new \DateTimeImmutable('-2 days'), new \DateTimeImmutable(), true);
        $this->executeDeferredJobs();
        self::assertCount(1, $this->variantsWritten($child->getIdentifier()));
    }

    public function testOrdinaryPropertyEditDoesNotFanOutToChildrenOrOtherLanguages(): void
    {
        $parent = $this->document('parent');
        $child = $parent->createNode('child', $this->types->getNodeType('Medienreaktor.Meilisearch.Testing:Document'));
        $this->persistChanges();
        $this->index->calls = [];
        $draftParent = $this->context('user-test')->getNodeByIdentifier($parent->getIdentifier());
        $draftParent->setProperty('title', 'Updated title');
        $this->draft->publishNode($draftParent, $this->live);
        $this->persistChanges();
        self::assertCount(2, $this->variantsWritten($parent->getIdentifier()));
        self::assertSame([], $this->variantsWritten($child->getIdentifier()));
        self::assertSame([], $this->deletedDocumentsFor($child->getIdentifier()));
    }

    public function testRepeatedPathEventsAreDeduplicatedWithinThePersistenceBatch(): void
    {
        $node = $this->document('document');
        $this->persistChanges();
        $this->index->calls = [];
        $this->repairs->nodePathChanged($node, '/old/document', '/document', false);
        $this->repairs->nodePathChanged($node, '/old/document', '/document', false);
        $operationsProperty = new \ReflectionProperty(RuntimeIndexingService::class, 'operations');
        $operationsProperty->setAccessible(true);
        $operations = $operationsProperty->getValue($this->repairs);
        self::assertCount(2, $operations);
        $this->repairs->flush();
        $this->executeDeferredJobs();
        self::assertCount(2, $this->variantsWritten($node->getIdentifier()));
        $this->index->calls = [];
        $this->repairs->flush();
        self::assertSame([], $this->index->calls);
    }

    public function testPublishingDeletionRemovesTheDocumentAfterNodeDataIsGone(): void
    {
        $node = $this->document('document');
        $child = $node->createNode('child', $this->types->getNodeType('Medienreaktor.Meilisearch.Testing:Document'));
        $this->persistChanges();
        $this->index->calls = [];
        $draftNode = $this->context('user-test')->getNodeByIdentifier($node->getIdentifier());
        $draftNode->remove();
        $this->draft->publishNode($draftNode, $this->live);
        $this->persistChanges();
        self::assertNotEmpty($this->deletedDocumentsFor($node->getIdentifier()));
        self::assertSame([], $this->variantsWritten($node->getIdentifier()));
        self::assertNotEmpty($this->deletedDocumentsFor($child->getIdentifier()));
        self::assertSame([], $this->variantsWritten($child->getIdentifier()));
    }

    protected function variantsWritten(string $identifier): array
    {
        $variants = [];
        foreach ($this->index->calls as [$method, $payload]) {
            if ($method === 'addDocuments') {
                foreach ($payload as $document) {
                    if ($document['__identifier'] === $identifier) {
                        $variants[$document['id']] = $document;
                    }
                }
            }
        }
        return $variants;
    }

    protected function document(string $name): NodeInterface
    {
        return $this->context('live')->getRootNode()->createNode($name, $this->types->getNodeType('Medienreaktor.Meilisearch.Testing:Document'));
    }

    protected function context(string $workspace)
    {
        return $this->contexts->create([
            'workspaceName' => $workspace, 'dimensions' => ['language' => ['en']],
            'invisibleContentShown' => true, 'inaccessibleContentShown' => true,
        ]);
    }

    protected function documentsWritten(): array
    {
        $documents = [];
        foreach ($this->index->calls as [$method, $payload]) {
            if ($method === 'addDocuments') {
                foreach ($payload as $document) {
                    $documents[$document['__identifier']] = $document;
                }
            }
        }
        return $documents;
    }

    protected function deletedDocumentsFor(string $identifier): array
    {
        $deleted = [];
        foreach ($this->index->calls as [$method, $payload]) {
            if ($method === 'deleteDocuments') {
                $deleted = array_merge($deleted, array_filter($payload, static fn(string $id): bool => str_starts_with($id, $identifier . '_')));
            }
        }
        return $deleted;
    }

    protected function tearDown(): void
    {
        $this->inject($this->contexts, 'contextInstances', []);
        parent::tearDown();
    }
}
