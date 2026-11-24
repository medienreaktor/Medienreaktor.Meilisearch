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
 *
 * Some writes are awaited task by task, so a lookup of a single task is answered with
 * that task in $taskStatus. Reading anything else back from Meilisearch - asking
 * whether the queue has drained, searching - needs answers this default cannot give,
 * so bodies queued in $responses are handed out in order before it applies.
 */
final class RecordingHttpClient implements ClientInterface
{
    /**
     * @var list<RequestInterface>
     */
    public array $requests = [];

    /**
     * Response bodies to answer with, in order, before falling back to the default.
     *
     * @var list<array<string, mixed>>
     */
    public array $responses = [];

    /**
     * What a lookup of a single task reports as its status.
     */
    public string $taskStatus = 'succeeded';

    private int $lastTaskUid = 0;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($request->getMethod() === 'GET' && preg_match('#^/tasks/(\d+)$#', $request->getUri()->getPath(), $matches) === 1) {
            $task = ['uid' => (int)$matches[1], 'indexUid' => 'test', 'status' => $this->taskStatus, 'type' => 'documentAdditionOrUpdate'];
            if ($this->taskStatus === 'failed') {
                $task['error'] = ['message' => 'index primary key mismatch'];
            }
            return $this->json(200, $task);
        }

        if ($this->responses !== []) {
            return $this->json(200, array_shift($this->responses));
        }

        return $this->json(202, [
            'taskUid' => ++$this->lastTaskUid,
            'indexUid' => 'test',
            'status' => 'enqueued',
            'type' => 'documentDeletion',
            'enqueuedAt' => '2026-01-01T00:00:00Z',
        ]);
    }

    /**
     * The paths of every request recorded so far, which is what most assertions about
     * request shape are actually about.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(static fn (RequestInterface $request): string => $request->getUri()->getPath(), $this->requests);
    }

    /**
     * The requests that change something, each of which Meilisearch queues as a task.
     *
     * @return list<RequestInterface>
     */
    public function writes(): array
    {
        return array_values(array_filter($this->requests, static fn (RequestInterface $request): bool => $request->getMethod() !== 'GET'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string)json_encode($body));
    }
}
