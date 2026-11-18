<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryDependencies;
use Neos\ContentRepository\Core\Factory\ContentRepositoryServiceFactoryInterface;

/**
 * @implements ContentRepositoryServiceFactoryInterface<LiveEventReader>
 */
final class LiveEventReaderFactory implements ContentRepositoryServiceFactoryInterface
{
    public function build(ContentRepositoryServiceFactoryDependencies $serviceFactoryDependencies): LiveEventReader
    {
        return new LiveEventReader(
            $serviceFactoryDependencies->eventStore,
            $serviceFactoryDependencies->eventNormalizer,
        );
    }
}
