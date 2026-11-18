<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

enum IndexingScope
{
    /** Reindex the document the node belongs to. */
    case Node;
    /** Reindex that document and every document below the node. */
    case Subtree;
    /** The node aggregate is gone: drop its documents from the index. */
    case Removed;
}
