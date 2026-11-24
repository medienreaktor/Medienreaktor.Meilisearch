<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

enum IndexCatchUpOutcome
{
    case CaughtUp;
    /** No position was stored: it is now set to the current event, nothing was indexed. */
    case Initialized;
    /**
     * The event store no longer holds the event the stored position names: it was
     * replaced. The position is now set to the current event, nothing was indexed, and
     * the index needs a rebuild to cover the difference.
     */
    case Reset;
    /** Another run holds the lock; this one did nothing. */
    case AlreadyRunning;
}
