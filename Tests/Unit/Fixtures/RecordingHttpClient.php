<?php

declare(strict_types=1);

namespace Medienreaktor\Meilisearch\Tests\Unit\Fixtures;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Records requests instead of sending them, answering with a plausible enqueued-task
 * response so the Meilisearch client accepts it. A real double rather than a mock:
 * the assertions here are about the request that leaves the client, and PHPUnit's
 * mock builder is internal API that moves between the major versions this package
 * supports.
 */
final class RecordingHttpClient implements ClientInterface
{
    /**
     * @var list<RequestInterface>
     */
    public array $requests = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return new Response(202, ['Content-Type' => 'application/json'], (string) json_encode([
            'taskUid' => 1,
            'indexUid' => 'test',
            'status' => 'enqueued',
            'type' => 'documentDeletion',
            'enqueuedAt' => '2026-01-01T00:00:00Z',
        ]));
    }
}
