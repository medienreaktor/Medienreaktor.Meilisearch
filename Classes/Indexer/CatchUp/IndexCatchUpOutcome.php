<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

enum IndexCatchUpOutcome
{
    case CaughtUp;
    /** No position was stored: it is now set to the current event, nothing was indexed. */
    case Initialized;
    /** Another run holds the lock; this one did nothing. */
    case AlreadyRunning;
}
