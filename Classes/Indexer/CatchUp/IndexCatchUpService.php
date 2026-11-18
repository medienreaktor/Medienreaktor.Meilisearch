<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

use Neos\Cache\Frontend\StringFrontend;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Utility\Environment;
use Psr\Log\LoggerInterface;

/**
 * Keeps the index in step with live between nightly rebuilds, run by cron.
 *
 * The position of the last event handled is kept per content repository in a persistent
 * cache. It only moves forward once a batch is indexed, so a failed run is retried by the
 * next one. Without a position (first run, cache flushed) the run only records where live
 * stands: the nightly rebuild covers everything before.
 *
 * @Flow\Scope("singleton")
 */
class IndexCatchUpService
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected DocumentCatchUp $documentCatchUp;

    #[Flow\Inject]
    protected Environment $environment;

    #[Flow\Inject(name: 'Neos.Flow:SystemLogger')]
    protected LoggerInterface $logger;

    /**
     * Configured in Objects.yaml
     * @var StringFrontend
     */
    protected $positionCache;

    public function catchUp(ContentRepositoryId $contentRepositoryId, int $batchSize): IndexCatchUpResult
    {
        $lock = $this->acquireLock($contentRepositoryId);
        if ($lock === null) {
            return IndexCatchUpResult::alreadyRunning();
        }

        try {
            return $this->catchUpLocked($contentRepositoryId, $batchSize);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function catchUpLocked(ContentRepositoryId $contentRepositoryId, int $batchSize): IndexCatchUpResult
    {
        $reader = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new LiveEventReaderFactory());

        $position = $this->loadPosition($contentRepositoryId);
        if ($position === null) {
            $head = $reader->head();
            $this->savePosition($contentRepositoryId, $head);
            $this->logger->info('Meilisearch catch-up had no position yet and starts after the current event.', ['contentRepository' => $contentRepositoryId->value, 'position' => $head->value]);
            return IndexCatchUpResult::initialized($head);
        }

        $batch = $reader->readAfter($position, $batchSize);
        $documentCount = $batch->impacts === [] ? 0 : $this->documentCatchUp->apply($contentRepositoryId, $batch->impacts);
        $this->savePosition($contentRepositoryId, $batch->lastSequenceNumber);

        if ($documentCount > 0) {
            $this->logger->info('Meilisearch catch-up indexed live changes.', [
                'contentRepository' => $contentRepositoryId->value,
                'from' => $position->value,
                'to' => $batch->lastSequenceNumber->value,
                'events' => $batch->eventCount,
                'documents' => $documentCount,
            ]);
        }

        return IndexCatchUpResult::caughtUp($position, $batch, $documentCount, $batch->eventCount === $batchSize);
    }

    private function loadPosition(ContentRepositoryId $contentRepositoryId): ?SequenceNumber
    {
        $stored = $this->positionCache->get($this->cacheKey($contentRepositoryId));
        if ($stored === false) {
            return null;
        }
        if (!ctype_digit($stored)) {
            throw new \RuntimeException(sprintf('The stored Meilisearch catch-up position "%s" for content repository "%s" is not a sequence number.', $stored, $contentRepositoryId->value), 1795000101);
        }

        return SequenceNumber::fromInteger((int)$stored);
    }

    private function savePosition(ContentRepositoryId $contentRepositoryId, SequenceNumber $position): void
    {
        $this->positionCache->set($this->cacheKey($contentRepositoryId), (string)$position->value);
    }

    private function cacheKey(ContentRepositoryId $contentRepositoryId): string
    {
        return 'position_' . $contentRepositoryId->value;
    }

    /**
     * @return resource|null Null when another run holds the lock
     */
    private function acquireLock(ContentRepositoryId $contentRepositoryId)
    {
        $path = $this->environment->getPathToTemporaryDirectory() . 'Meilisearch_CatchUp_' . $contentRepositoryId->value . '.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Could not open the Meilisearch catch-up lock file "%s".', $path), 1795000102);
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }
}
