<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Indexer\CatchUp;

use Medienreaktor\Meilisearch\Indexer\CatchUp\IndexingImpact;
use Medienreaktor\Meilisearch\Indexer\CatchUp\IndexingScope;
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Event\SubtreeWasTagged;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Event\RootWorkspaceWasCreated;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @see IndexingImpact
 */
final class IndexingImpactTest extends TestCase
{
    private const NODE = 'b2b0b2c4-8e3e-4c33-9cf3-2a1f6c3f0a11';

    #[Test]
    public function aPropertyChangeInLiveReindexesTheNodesDocument(): void
    {
        $impact = IndexingImpact::fromEvent(NodePropertiesWereSet::fromArray([
            'workspaceName' => 'live',
            'contentStreamId' => 'cs-live',
            'nodeAggregateId' => self::NODE,
            'originDimensionSpacePoint' => ['language' => 'de'],
            'affectedDimensionSpacePoints' => [['language' => 'de']],
            'propertyValues' => [],
            'propertiesToUnset' => [],
        ]));

        self::assertNotNull($impact);
        self::assertSame(self::NODE, $impact->nodeAggregateId->value);
        self::assertSame(IndexingScope::Node, $impact->scope);
    }

    #[Test]
    public function aChangeInAnotherWorkspaceDoesNotTouchTheIndex(): void
    {
        self::assertNull(IndexingImpact::fromEvent(NodePropertiesWereSet::fromArray([
            'workspaceName' => 'user-editor',
            'contentStreamId' => 'cs-user',
            'nodeAggregateId' => self::NODE,
            'originDimensionSpacePoint' => ['language' => 'de'],
            'affectedDimensionSpacePoints' => [['language' => 'de']],
            'propertyValues' => [],
            'propertiesToUnset' => [],
        ])));
    }

    #[Test]
    public function taggingReachesTheWholeSubtree(): void
    {
        $impact = IndexingImpact::fromEvent(SubtreeWasTagged::fromArray([
            'workspaceName' => 'live',
            'contentStreamId' => 'cs-live',
            'nodeAggregateId' => self::NODE,
            'affectedDimensionSpacePoints' => [['language' => 'de']],
            'tag' => 'removed',
        ]));

        self::assertSame(IndexingScope::Subtree, $impact?->scope);
    }

    #[Test]
    public function removalDropsTheDocuments(): void
    {
        $impact = IndexingImpact::fromEvent(NodeAggregateWasRemoved::fromArray([
            'workspaceName' => 'live',
            'contentStreamId' => 'cs-live',
            'nodeAggregateId' => self::NODE,
            'affectedCoveredDimensionSpacePoints' => [['language' => 'de']],
        ]));

        self::assertSame(IndexingScope::Removed, $impact?->scope);
    }

    #[Test]
    public function anEventWithoutANodeIsIgnored(): void
    {
        self::assertNull(IndexingImpact::fromEvent(RootWorkspaceWasCreated::fromArray([
            'workspaceName' => 'live',
            'newContentStreamId' => 'cs-live',
        ])));
    }
}
