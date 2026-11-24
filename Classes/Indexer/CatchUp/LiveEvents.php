<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

/**
 * The content repository's events as the catch-up reads them.
 */
interface LiveEvents
{
    /**
     * The position of the newest event.
     */
    public function head(): CatchUpPosition;

    /**
     * Whether the event store still holds the event the position names. It does not once
     * it has been replaced by another one, such as a restored backup or a mirror.
     */
    public function holds(CatchUpPosition $position): bool;

    /**
     * At most $limit events after the position.
     */
    public function readAfter(CatchUpPosition $after, int $limit): LiveEventBatch;
}
