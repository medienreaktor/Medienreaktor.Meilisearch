<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Indexer\CatchUp;

use Medienreaktor\Meilisearch\Indexer\NodeIndexer;
use Neos\Cache\Frontend\StringFrontend;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Utility\Environment;
use Psr\Log\LoggerInterface;

/**
 * Keeps the index in step with live between nightly rebuilds, run by cron.
 *
 * The position of the last event handled is kept per content repository and index in a
 * persistent cache - per index, because it describes what that index has seen. It only
 * moves forward once a batch is indexed, so a failed run is retried by the next one.
 * Without a position (first run, cache flushed) the run only records where live stands:
 * the nightly rebuild covers everything before. nodeindex:build and nodeindex:rebuild
 * record the event they started at.
 *
 * A position names its event by id as well, so that an event store replaced underneath
 * it - a restored backup, a mirrored database - is noticed: its sequence numbers name
 * other events, or none. The position is then reset to the current event, and the index
 * needs a rebuild; replaying from the old number would skip or misread events.
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
    protected NodeIndexer $nodeIndexer;

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

    /**
     * The position of the newest event, for a build to record once it has indexed what
     * stood before it.
     */
    public function head(ContentRepositoryId $contentRepositoryId): CatchUpPosition
    {
        return $this->liveEvents($contentRepositoryId)->head();
    }

    /**
     * Record that the index covers every event up to the position.
     */
    public function recordPosition(ContentRepositoryId $contentRepositoryId, CatchUpPosition $position): void
    {
        $this->savePosition($contentRepositoryId, $position);
    }

    private function catchUpLocked(ContentRepositoryId $contentRepositoryId, int $batchSize): IndexCatchUpResult
    {
        $events = $this->liveEvents($contentRepositoryId);

        $position = $this->loadPosition($contentRepositoryId);
        if ($position === null) {
            $head = $events->head();
            $this->savePosition($contentRepositoryId, $head);
            $this->logger->info('Meilisearch catch-up had no position yet and starts after the current event.', ['contentRepository' => $contentRepositoryId->value, 'index' => $this->indexName(), 'position' => $head->sequenceNumber->value]);
            return IndexCatchUpResult::initialized($head->sequenceNumber);
        }

        if (!$events->holds($position)) {
            $head = $events->head();
            $this->savePosition($contentRepositoryId, $head);
            $this->logger->warning('The event store no longer holds the event the Meilisearch catch-up stood at; it was replaced. The position is reset to the current event, and the index needs a rebuild.', [
                'contentRepository' => $contentRepositoryId->value,
                'index' => $this->indexName(),
                'stored' => $position->toStored(),
                'position' => $head->toStored(),
            ]);
            return IndexCatchUpResult::reset($position->sequenceNumber, $head->sequenceNumber);
        }

        $batch = $events->readAfter($position, $batchSize);
        $documentCount = $batch->impacts === [] ? 0 : $this->documentCatchUp->apply($contentRepositoryId, $batch->impacts);
        $this->savePosition($contentRepositoryId, $batch->last);

        if ($documentCount > 0) {
            $this->logger->info('Meilisearch catch-up indexed live changes.', [
                'contentRepository' => $contentRepositoryId->value,
                'index' => $this->indexName(),
                'from' => $position->sequenceNumber->value,
                'to' => $batch->last->sequenceNumber->value,
                'events' => $batch->eventCount,
                'documents' => $documentCount,
            ]);
        }

        return IndexCatchUpResult::caughtUp($position->sequenceNumber, $batch, $documentCount, $batch->eventCount === $batchSize);
    }

    protected function liveEvents(ContentRepositoryId $contentRepositoryId): LiveEvents
    {
        return $this->contentRepositoryRegistry->buildService($contentRepositoryId, new LiveEventReaderFactory());
    }

    protected function indexName(): string
    {
        return $this->nodeIndexer->getIndexClient()->getIndexName();
    }

    /**
     * Falls back to the key positions were stored under before they were kept per
     * index, and moves such a position to its own key.
     */
    private function loadPosition(ContentRepositoryId $contentRepositoryId): ?CatchUpPosition
    {
        $key = $this->cacheKey($contentRepositoryId);
        $stored = $this->positionCache->get($key);
        if ($stored === false) {
            $legacyKey = 'position_' . $contentRepositoryId->value;
            $stored = $this->positionCache->get($legacyKey);
            if ($stored === false) {
                return null;
            }
            $this->positionCache->set($key, $stored);
            $this->positionCache->remove($legacyKey);
        }

        try {
            return CatchUpPosition::fromStored($stored);
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException(sprintf('The stored Meilisearch catch-up position "%s" for content repository "%s" is not a position.', $stored, $contentRepositoryId->value), 1795000101, $exception);
        }
    }

    private function savePosition(ContentRepositoryId $contentRepositoryId, CatchUpPosition $position): void
    {
        $this->positionCache->set($this->cacheKey($contentRepositoryId), $position->toStored());
    }

    private function cacheKey(ContentRepositoryId $contentRepositoryId): string
    {
        return 'position_' . $contentRepositoryId->value . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->indexName());
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
