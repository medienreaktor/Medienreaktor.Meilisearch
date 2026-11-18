<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\Common\EmbedsNodeAggregateId;
use Neos\ContentRepository\Core\Feature\Common\EmbedsWorkspaceName;
use Neos\ContentRepository\Core\Feature\NodeMove\Event\NodeAggregateWasMoved;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Event\SubtreeWasTagged;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Event\SubtreeWasUntagged;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;

/**
 * What a single content repository event means for the search index.
 *
 * Only events in the live workspace count, the index holds live content only. Any event
 * naming a node aggregate is taken as a change to it, so event types added later are
 * picked up rather than silently ignored. Tagging, untagging and moving change every
 * document below the node (visibility, URI), removal takes the node out of the index.
 */
final readonly class IndexingImpact
{
    private function __construct(
        public NodeAggregateId $nodeAggregateId,
        public IndexingScope $scope,
    ) {
    }

    public static function fromEvent(EventInterface $event): ?self
    {
        if (!$event instanceof EmbedsWorkspaceName || !$event->getWorkspaceName()->isLive()) {
            return null;
        }
        if (!$event instanceof EmbedsNodeAggregateId) {
            return null;
        }

        $scope = match (true) {
            $event instanceof NodeAggregateWasRemoved => IndexingScope::Removed,
            $event instanceof SubtreeWasTagged,
            $event instanceof SubtreeWasUntagged,
            $event instanceof NodeAggregateWasMoved => IndexingScope::Subtree,
            default => IndexingScope::Node,
        };

        return new self($event->getNodeAggregateId(), $scope);
    }
}
