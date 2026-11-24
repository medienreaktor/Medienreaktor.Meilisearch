<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Domain\Service;

use Medienreaktor\Meilisearch\Domain\Service\MeilisearchIndex;
use Medienreaktor\Meilisearch\Tests\Unit\Fixtures\RecordingHttpClient;
use Meilisearch\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

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
        $properties = ['client' => $client, 'index' => $client->index($indexName)];
        if ($batchSize !== null) {
            $properties['indexingSettings'] = ['batchSize' => $batchSize];
        }

        $reflection = new \ReflectionObject($index);
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($index, $value);
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

    /**
     * @return array<mixed>
     */
    private function decodedBody(RequestInterface $request): array
    {
        return (array)json_decode((string)$request->getBody(), true);
    }

    /**
     * @return list<string>
     */
    private function writePaths(): array
    {
        return array_map(static fn (RequestInterface $request): string => $request->getUri()->getPath(), $this->transport->writes());
    }

    public function testDeleteByFilterReachesTheFilterEndpointAndNotTheBatchEndpoint(): void
    {
        $this->index('test')->deleteByFilter(['__identifier = "abc"']);

        // documents/delete-batch would silently delete nothing: it reads its payload as
        // a list of document ids, and a filter object is not one.
        self::assertSame(['/indexes/test/documents/delete'], $this->transport->paths());
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
            $this->decodedBody($this->transport->requests[0])
        );
    }

    public function testDeleteByFilterSendsAFilterStringAsItIs(): void
    {
        $this->index('test')->deleteByFilter('__identifier = "abc" OR __identifier = "def"');

        self::assertSame(['/indexes/test/documents/delete'], $this->transport->paths());
        self::assertSame(
            ['filter' => '__identifier = "abc" OR __identifier = "def"'],
            $this->decodedBody($this->transport->requests[0])
        );
    }

    /**
     * Callers that already hold the options Meilisearch takes pass them through rather
     * than having their filter wrapped a second time.
     */
    public function testDeleteByFilterPassesAnOptionsArrayThrough(): void
    {
        $this->index('test')->deleteByFilter(['filter' => '__identifier = "abc"']);

        self::assertSame(['/indexes/test/documents/delete'], $this->transport->paths());
        self::assertSame(['filter' => '__identifier = "abc"'], $this->decodedBody($this->transport->requests[0]));
    }

    /**
     * An empty filter matches every document, so sending one would empty the index.
     */
    public function testDeleteByFilterSendsNothingForAnEmptyFilter(): void
    {
        $this->index('test')->deleteByFilter([]);
        $this->index('test')->deleteByFilter('');

        self::assertSame([], $this->transport->requests);
    }

    public function testDeleteByFilterDeletesFromTheTargetIndexWhenOneIsGiven(): void
    {
        $this->index('test')->deleteByFilter(['__identifier = "abc"'], 'test-build');

        self::assertSame(['/indexes/test-build/documents/delete'], $this->transport->paths());
    }

    /**
     * Meilisearch queues one task per write request, so a write count that is the same
     * for ten documents and for a hundred is what keeps the queue from growing with the
     * site. Asserted as an equality between two sizes rather than against a fixed
     * number, which a per-document implementation could also satisfy.
     */
    public function testAddDocumentsSendsTheSameNumberOfWritesForTenAndAHundredDocuments(): void
    {
        $this->index('test')->addDocuments($this->documents(10));
        $forTen = count($this->transport->writes());

        $this->transport->requests = [];
        $this->index('test')->addDocuments($this->documents(100));

        self::assertSame($forTen, count($this->transport->writes()));
        self::assertSame(1, $forTen);
    }

    public function testAddDocumentsSplitsIntoOneRequestPerBatch(): void
    {
        $this->index('test', 10)->addDocuments($this->documents(25));

        $writes = $this->transport->writes();
        self::assertCount(3, $writes);
        self::assertSame(
            [10, 10, 5],
            array_map(fn (RequestInterface $request): int => count($this->decodedBody($request)), $writes)
        );
    }

    public function testAddDocumentsWritesToTheTargetIndexWhenOneIsGiven(): void
    {
        $this->index('test')->addDocuments($this->documents(1), 'test-build');

        self::assertSame(['/indexes/test-build/documents'], $this->writePaths());
    }

    public function testAddDocumentsSendsNothingForNoDocuments(): void
    {
        $this->index('test')->addDocuments([]);

        self::assertSame([], $this->transport->requests);
    }

    /**
     * Additions are awaited, so a rejected batch surfaces where it was written instead
     * of leaving a silently incomplete index behind.
     */
    public function testAddDocumentsFailsWhenItsTaskFailed(): void
    {
        $this->transport->taskStatus = 'failed';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/index primary key mismatch/');

        $this->index('test')->addDocuments($this->documents(1));
    }

    public function testDeleteDocumentsSendsOneRequestForManyIdentifiers(): void
    {
        $this->index('test')->deleteDocuments($this->identifiers(100));

        self::assertSame(['/indexes/test/documents/delete-batch'], $this->transport->paths());
        self::assertCount(100, $this->decodedBody($this->transport->requests[0]));
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

        self::assertSame(['/indexes/test/documents/delete'], $this->transport->paths());
        self::assertSame(
            ['filter' => '__identifier IN ["abc", "def", "ghi"]'],
            $this->decodedBody($this->transport->requests[0])
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

    public function testFindAllIdentifiersByIdentifierAndDimensionsHashFiltersByBoth(): void
    {
        $this->transport->responses = [[
            'hits' => [['id' => 'abc_h1'], ['id' => 'abc_h1-copy']],
            'query' => '',
            'processingTimeMs' => 1,
            'limit' => 20,
            'offset' => 0,
            'estimatedTotalHits' => 2,
        ]];

        $identifiers = $this->index('test')->findAllIdentifiersByIdentifierAndDimensionsHash('abc', 'h1');

        self::assertSame(['abc_h1', 'abc_h1-copy'], $identifiers);
        self::assertSame(['/indexes/test/search'], $this->transport->paths());
        self::assertSame(
            ['__identifier = "abc"', '__dimensionsHash = "h1"'],
            $this->decodedBody($this->transport->requests[0])['filter']
        );
    }

    /**
     * Custom indexes are configured in code, where the plain lists Meilisearch takes
     * are the natural spelling; they go out as lists all the same.
     */
    public function testUpdateCustomSettingsAcceptsPlainAttributeLists(): void
    {
        $this->index('test')->updateCustomSettings([
            'filterableAttributes' => ['__identifier', '__dimensionsHash'],
            'sortableAttributes' => ['_geo' => true, 'datePublished' => false],
            'searchableAttributes' => ['title'],
        ]);

        self::assertSame(['/indexes/test/settings'], $this->writePaths());
        self::assertSame(
            [
                'filterableAttributes' => ['__identifier', '__dimensionsHash'],
                'sortableAttributes' => ['_geo'],
                'searchableAttributes' => ['title'],
            ],
            $this->decodedBody($this->transport->writes()[0])
        );
    }

    public function testCustomSettingsApplyToALaterCreateIndex(): void
    {
        $index = $this->index('test');
        $index->updateCustomSettings(['filterableAttributes' => ['__identifier']]);
        $this->transport->requests = [];

        $index->createIndex();

        $settingsWrites = array_values(array_filter(
            $this->transport->writes(),
            static fn (RequestInterface $request): bool => $request->getUri()->getPath() === '/indexes/test/settings'
        ));
        self::assertCount(1, $settingsWrites);
        self::assertSame(['filterableAttributes' => ['__identifier']], $this->decodedBody($settingsWrites[0]));
    }

    /**
     * nodeindex:build --wait promises that the index has no unfinished work, whoever
     * queued it, so even a run that wrote nothing asks once.
     */
    public function testWaitingWithNothingEnqueuedAsksOnlyWhetherTheQueueHasDrained(): void
    {
        $this->transport->responses = [['results' => []]];

        $this->index('test')->waitForPendingTasks(30);

        self::assertSame(['/tasks/'], $this->transport->paths());
    }

    public function testWaitingReturnsOnceTheIndexReportsNoUnfinishedTask(): void
    {
        $index = $this->index('test');
        $index->deleteDocuments($this->identifiers(1));
        $this->transport->requests = [];
        $this->transport->responses = [['results' => []], ['results' => []]];

        $index->waitForPendingTasks(30);

        // One request to ask whether the queue has drained, one to ask whether anything
        // in it failed.
        self::assertSame(['/tasks/', '/tasks/'], $this->transport->paths());
    }

    public function testWaitingKeepsAskingWhileTheQueueStillHasWork(): void
    {
        $index = $this->index('test');
        $index->deleteDocuments($this->identifiers(1));
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
        $index->deleteDocuments($this->identifiers(1));
        $this->transport->responses = [
            ['results' => [['uid' => 7, 'type' => 'documentAdditionOrUpdate', 'status' => 'enqueued']]],
        ];

        $this->expectException(\Exception::class);
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
        $index->deleteDocuments($this->identifiers(1));
        $this->transport->responses = [
            ['results' => []],
            ['results' => [['uid' => 42, 'error' => ['message' => 'index primary key mismatch']]]],
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(1787000102);
        $this->expectExceptionMessageMatches('/index primary key mismatch/');

        $index->waitForPendingTasks(30);
    }
}
