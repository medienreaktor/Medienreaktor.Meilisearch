<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Domain\Service;

use Medienreaktor\Meilisearch\Domain\Service\MeilisearchIndex;
use Medienreaktor\Meilisearch\Exception;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\RecordingHttpClient;
use Meilisearch\Client;
use PHPUnit\Framework\TestCase;

/**
 * deleteByFilter() is load-bearing: the indexer clears an aggregate's stale variants
 * with it on every publish. Meilisearch exposes two different endpoints here —
 * documents/delete-batch takes a list of document ids, documents/delete takes a
 * filter — and a filter sent to the batch endpoint deletes nothing.
 *
 * Which of the two the client reaches depends on the meilisearch-php version, so
 * these assert the request our code actually produces rather than trusting the
 * constraint in composer.json.
 */
class MeilisearchIndexTest extends TestCase
{
    private RecordingHttpClient $transport;

    protected function setUp(): void
    {
        $this->transport = new RecordingHttpClient();
    }

    /**
     * Seeds a real Meilisearch client onto the recording transport, the way
     * initializeObject() would build one from Flow settings.
     */
    private function index(string $indexName, ?int $batchSize = null): MeilisearchIndex
    {
        $client = new Client('http://meilisearch.invalid', 'masterKey', $this->transport);

        $index = new MeilisearchIndex($indexName);
        $reflection = new \ReflectionObject($index);
        foreach (['client' => $client, 'index' => $client->index($indexName)] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($index, $value);
        }

        if ($batchSize !== null) {
            $property = $reflection->getProperty('indexingSettings');
            $property->setAccessible(true);
            $property->setValue($index, ['batchSize' => $batchSize]);
        }

        return $index;
    }

    /**
     * @return list<array<string, string>>
     */
    private function documents(int $count): array
    {
        $documents = [];
        for ($i = 0; $i < $count; $i++) {
            $documents[] = ['id' => 'document-' . $i];
        }

        return $documents;
    }

    /**
     * @return list<string>
     */
    private function identifiers(int $count): array
    {
        $identifiers = [];
        for ($i = 0; $i < $count; $i++) {
            $identifiers[] = 'node-' . $i;
        }

        return $identifiers;
    }

    private function decodedBody(int $position): array
    {
        return (array) json_decode((string) $this->transport->requests[$position]->getBody(), true);
    }

    private function indexWithSettings(array $settings): MeilisearchIndex
    {
        $index = $this->index('test');
        $property = (new \ReflectionObject($index))->getProperty('indexSettings');
        $property->setAccessible(true);
        $property->setValue($index, $settings);

        return $index;
    }

    /**
     * Queues what the server answers before a settings update: the index's stats,
     * then its current settings.
     */
    private function serverHolds(int $documentCount, array $embedders): void
    {
        $this->transport->responses = [
            ['numberOfDocuments' => $documentCount, 'isIndexing' => false, 'fieldDistribution' => []],
            ['embedders' => $embedders],
        ];
    }

    /**
     * The body of the settings update, which is the request whose content matters.
     */
    private function settingsUpdate(): array
    {
        $found = null;
        foreach ($this->transport->requests as $position => $request) {
            if ($request->getMethod() === 'PATCH' && $request->getUri()->getPath() === '/indexes/test/settings') {
                $found = $position;
            }
        }

        self::assertNotNull($found, 'No settings update was sent.');

        return $this->decodedBody($found);
    }

    /**
     * @return array<string, string>
     */
    private function embedder(string $documentTemplate, string $apiKey): array
    {
        return [
            'source' => 'openAi',
            'model' => 'text-embedding-3-large',
            'documentTemplate' => $documentTemplate,
            'apiKey' => $apiKey,
        ];
    }

    public function testDeleteByFilterReachesTheFilterEndpointAndNotTheBatchEndpoint(): void
    {
        $this->index('test')->deleteByFilter(['__identifier = "abc"']);

        self::assertCount(1, $this->transport->requests);

        // documents/delete-batch would silently delete nothing: it reads its payload as
        // a list of document ids, and a filter object is not one.
        self::assertSame(
            '/indexes/test/documents/delete',
            $this->transport->requests[0]->getUri()->getPath()
        );
    }

    public function testDeleteByFilterSendsTheJoinedFilterAsItsPayload(): void
    {
        $this->index('test')->deleteByFilter([
            '__identifier = "abc"',
            '__dimensionsHash = "def"',
        ]);

        self::assertCount(1, $this->transport->requests);
        self::assertSame(
            ['filter' => '__identifier = "abc" AND __dimensionsHash = "def"'],
            json_decode((string) $this->transport->requests[0]->getBody(), true)
        );
    }

    public function testDeleteByFilterSendsNothingForAnEmptyFilter(): void
    {
        $this->index('test')->deleteByFilter([]);

        self::assertSame([], $this->transport->requests);
    }

    /**
     * Meilisearch queues one task per write request, so a request count that is the
     * same for ten documents and for a hundred is what keeps the queue from growing
     * with the site. Asserted as an equality between two sizes rather than against a
     * fixed number, which a per-document implementation could also satisfy.
     */
    public function testAddDocumentsSendsTheSameNumberOfRequestsForTenAndAHundredDocuments(): void
    {
        $this->index('test')->addDocuments($this->documents(10));
        $forTen = count($this->transport->requests);

        $this->transport->requests = [];
        $this->index('test')->addDocuments($this->documents(100));

        self::assertSame($forTen, count($this->transport->requests));
        self::assertSame(1, $forTen);
    }

    public function testAddDocumentsSplitsIntoOneRequestPerBatch(): void
    {
        $this->index('test', 10)->addDocuments($this->documents(25));

        self::assertCount(3, $this->transport->requests);
        self::assertSame(
            [10, 10, 5],
            array_map(fn (int $position): int => count($this->decodedBody($position)), [0, 1, 2])
        );
    }

    public function testAddDocumentsSendsNothingForNoDocuments(): void
    {
        $this->index('test')->addDocuments([]);

        self::assertSame([], $this->transport->requests);
    }

    public function testDeleteDocumentsSendsOneRequestForManyIdentifiers(): void
    {
        $this->index('test')->deleteDocuments($this->identifiers(100));

        self::assertCount(1, $this->transport->requests);
        self::assertSame('/indexes/test/documents/delete-batch', $this->transport->paths()[0]);
        self::assertCount(100, $this->decodedBody(0));
    }

    public function testDeleteDocumentsSplitsIntoOneRequestPerBatch(): void
    {
        $this->index('test', 10)->deleteDocuments($this->identifiers(25));

        self::assertCount(3, $this->transport->requests);
    }

    public function testDeleteDocumentsSendsNothingForNoIdentifiers(): void
    {
        $this->index('test')->deleteDocuments([]);

        self::assertSame([], $this->transport->requests);
    }

    /**
     * A node's variants carry a dimensions hash in their document id, so clearing them
     * has to go by filter. One `IN` expression covers a whole batch of aggregates.
     */
    public function testDeleteByIdentifiersSendsOneFilterRequestForManyAggregates(): void
    {
        $this->index('test')->deleteByIdentifiers(['abc', 'def', 'ghi']);

        self::assertCount(1, $this->transport->requests);
        self::assertSame('/indexes/test/documents/delete', $this->transport->paths()[0]);
        self::assertSame(
            ['filter' => '__identifier IN ["abc", "def", "ghi"]'],
            $this->decodedBody(0)
        );
    }

    public function testDeleteByIdentifiersSplitsIntoOneRequestPerBatch(): void
    {
        $this->index('test', 10)->deleteByIdentifiers($this->identifiers(25));

        self::assertCount(3, $this->transport->requests);
    }

    public function testDeleteByIdentifiersSendsNothingForNoAggregates(): void
    {
        $this->index('test')->deleteByIdentifiers([]);

        self::assertSame([], $this->transport->requests);
    }

    public function testWaitingAsksNothingWhenNoTaskWasEnqueued(): void
    {
        $this->index('test')->waitForPendingTasks(30);

        self::assertSame([], $this->transport->requests);
    }

    public function testWaitingReturnsOnceTheIndexReportsNoUnfinishedTask(): void
    {
        $index = $this->index('test');
        $index->addDocuments($this->documents(1));
        $this->transport->requests = [];

        $index->waitForPendingTasks(30);

        // One request to ask whether the queue has drained, one to ask whether anything
        // in it failed.
        self::assertSame(['/tasks/', '/tasks/'], $this->transport->paths());
    }

    public function testWaitingKeepsAskingWhileTheQueueStillHasWork(): void
    {
        $index = $this->index('test');
        $index->addDocuments($this->documents(1));
        $this->transport->requests = [];
        $this->transport->responses = [
            ['results' => [['uid' => 5, 'type' => 'documentAdditionOrUpdate', 'status' => 'processing']]],
            ['results' => []],
            ['results' => []],
        ];

        $index->waitForPendingTasks(30);

        self::assertCount(3, $this->transport->requests);
    }

    /**
     * A slot must not go live on an index that is still filling, so a wait that runs
     * out has to fail rather than return.
     */
    public function testWaitingFailsWhenTheQueueDoesNotDrainInTime(): void
    {
        $index = $this->index('test');
        $index->addDocuments($this->documents(1));
        $this->transport->responses = [
            ['results' => [['uid' => 7, 'type' => 'documentAdditionOrUpdate', 'status' => 'enqueued']]],
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionCode(1787000101);
        // Names what is holding the index up, which is what someone reading a failed
        // deployment needs.
        $this->expectExceptionMessageMatches('/task 7 \(documentAdditionOrUpdate\) is enqueued/');

        $index->waitForPendingTasks(0);
    }

    /**
     * Meilisearch drops a failed task from its queue like any other, so a drained queue
     * does not mean a complete index.
     */
    public function testWaitingFailsWhenATaskFailed(): void
    {
        $index = $this->index('test');
        $index->addDocuments($this->documents(1));
        $this->transport->responses = [
            ['results' => []],
            ['results' => [['uid' => 42, 'error' => ['message' => 'index primary key mismatch']]]],
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionCode(1787000102);
        $this->expectExceptionMessageMatches('/index primary key mismatch/');

        $index->waitForPendingTasks(30);
    }

    /**
     * A template that differs from the server's would make Meilisearch re-embed the
     * whole index inside the settings task; createIndex() runs on every deployment, so
     * that must not happen as a side effect. The new apiKey still has to get through.
     */
    public function testCreateIndexHoldsBackATemplateChangeOnAFilledIndexButAppliesTheApiKey(): void
    {
        $index = $this->indexWithSettings([
            'searchableAttributes' => ['title'],
            'embedders' => ['default' => $this->embedder('{{doc.__markdown}}', 'new-key')],
        ]);
        $this->serverHolds(386513, ['default' => $this->embedder('{{doc.title}} {{doc.__markdown}}', 'sk-…XXXX')]);

        $index->createIndex();

        self::assertSame(
            ['searchableAttributes' => ['title'], 'embedders' => ['default' => ['apiKey' => 'new-key']]],
            $this->settingsUpdate()
        );
        self::assertCount(1, $index->getSkippedEmbedderChanges());
        self::assertStringContainsString('documentTemplate', $index->getSkippedEmbedderChanges()[0]);
    }

    /**
     * Rotating a key is the case that has to keep working: only the masked apiKey
     * differs from the server, which Meilisearch applies without re-embedding.
     */
    public function testCreateIndexAppliesAnEmbedderWhoseOnlyChangeIsTheApiKey(): void
    {
        $embedder = $this->embedder('{{doc.__markdown}}', 'new-key');
        $index = $this->indexWithSettings(['embedders' => ['default' => $embedder]]);
        $this->serverHolds(10, ['default' => $this->embedder('{{doc.__markdown}}', 'sk-…XXXX')]);

        $index->createIndex();

        self::assertSame(['embedders' => ['default' => $embedder]], $this->settingsUpdate());
        self::assertSame([], $index->getSkippedEmbedderChanges());
    }

    /**
     * Adding an embedder embeds every document just as changing one does.
     */
    public function testCreateIndexDoesNotAddAnEmbedderToAFilledIndex(): void
    {
        $index = $this->indexWithSettings([
            'searchableAttributes' => ['title'],
            'embedders' => ['default' => $this->embedder('{{doc.__markdown}}', 'new-key')],
        ]);
        $this->serverHolds(10, []);

        $index->createIndex();

        self::assertSame(['searchableAttributes' => ['title']], $this->settingsUpdate());
        self::assertStringContainsString('not on the server yet', $index->getSkippedEmbedderChanges()[0]);
    }

    public function testCreateIndexAppliesEmbedderChangesToAnEmptyIndex(): void
    {
        $settings = ['embedders' => ['default' => $this->embedder('{{doc.__markdown}}', 'new-key')]];
        $index = $this->indexWithSettings($settings);
        $this->serverHolds(0, ['default' => $this->embedder('{{doc.title}}', 'sk-…XXXX')]);

        $index->createIndex();

        self::assertSame($settings, $this->settingsUpdate());
    }

    /**
     * Asked for explicitly, the change goes through without even reading the server.
     */
    public function testCreateIndexAppliesEmbedderChangesWhenReembeddingIsAllowed(): void
    {
        $settings = ['embedders' => ['default' => $this->embedder('{{doc.__markdown}}', 'new-key')]];
        $index = $this->indexWithSettings($settings);
        $index->allowReembedding();

        $index->createIndex();

        self::assertSame(['/indexes', '/indexes/test/settings'], $this->transport->paths());
        self::assertSame($settings, $this->settingsUpdate());
    }

    /**
     * Other indexes reuse this class with their own settings - the chat store among
     * them - so the guard has to cover that path too.
     */
    public function testUpdateCustomSettingsHoldsBackATemplateChangeOnAFilledIndex(): void
    {
        $index = $this->index('test');
        $this->serverHolds(83036, ['default' => $this->embedder('answer: {{doc.chatCompletion}}', 'sk-…XXXX')]);

        $index->updateCustomSettings(['embedders' => ['default' => $this->embedder('{{doc.__markdown}}', 'new-key')]]);

        self::assertSame(['embedders' => ['default' => ['apiKey' => 'new-key']]], $this->settingsUpdate());
    }
}
