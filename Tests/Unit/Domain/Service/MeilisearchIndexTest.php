<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Domain\Service;

use Medienreaktor\Meilisearch\Domain\Service\MeilisearchIndex;
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
    private function index(string $indexName): MeilisearchIndex
    {
        $client = new Client('http://meilisearch.invalid', 'masterKey', $this->transport);

        $index = new MeilisearchIndex($indexName);
        $reflection = new \ReflectionObject($index);
        foreach (['client' => $client, 'index' => $client->index($indexName)] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($index, $value);
        }

        return $index;
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
}
