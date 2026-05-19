<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Processor;

use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Dto\RequestData;
use Duyler\HttpServer\Processor\RequestQueue;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestQueueTest extends TestCase
{
    private RequestQueue $queue;

    #[Override]
    protected function setUp(): void
    {
        $this->queue = new RequestQueue();
    }

    #[Test]
    public function enqueue_and_dequeue_single_request(): void
    {
        $request = new ServerRequest('GET', '/test');
        $requestData = new RequestData('req_0', $request, 1);
        $connection = $this->createMock(ConnectionInterface::class);

        $this->queue->enqueue($requestData, [
            'connection' => $connection,
            'timestamp' => microtime(true),
            'cors_origin' => null,
        ]);

        self::assertTrue($this->queue->hasRequest());

        $dequeued = $this->queue->dequeue();
        self::assertNotNull($dequeued);
        self::assertSame('req_0', $dequeued->id);

        $this->queue->remove('req_0');
        self::assertFalse($this->queue->hasRequest());
    }

    #[Test]
    public function dequeue_returns_null_when_empty(): void
    {
        self::assertNull($this->queue->dequeue());
    }

    #[Test]
    public function has_request_returns_false_when_empty(): void
    {
        self::assertFalse($this->queue->hasRequest());
    }

    #[Test]
    public function fifo_order_preserved(): void
    {
        $request1 = new ServerRequest('GET', '/first');
        $request2 = new ServerRequest('GET', '/second');
        $request3 = new ServerRequest('GET', '/third');

        $requestData1 = new RequestData('req_1', $request1, 1);
        $requestData2 = new RequestData('req_2', $request2, 2);
        $requestData3 = new RequestData('req_3', $request3, 3);

        $connection = $this->createMock(ConnectionInterface::class);

        $this->queue->enqueue($requestData1, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);
        $this->queue->enqueue($requestData2, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);
        $this->queue->enqueue($requestData3, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

        self::assertSame('req_1', $this->queue->dequeue()->id);
        self::assertSame('req_2', $this->queue->dequeue()->id);
        self::assertSame('req_3', $this->queue->dequeue()->id);
    }

    #[Test]
    public function remove_deletes_context(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $requestData = new RequestData('req_0', new ServerRequest('GET', '/test'), 1);

        $this->queue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

        self::assertTrue($this->queue->hasPendingResponse());
        self::assertSame(1, $this->queue->getPendingRequestCount());

        $this->queue->remove('req_0');

        self::assertFalse($this->queue->hasPendingResponse());
        self::assertSame(0, $this->queue->getPendingRequestCount());
    }

    #[Test]
    public function remove_nonexistent_id_does_nothing(): void
    {
        $this->queue->remove('nonexistent');
        self::assertSame(0, $this->queue->getPendingRequestCount());
    }

    #[Test]
    public function remove_by_connection_removes_matching_entries(): void
    {
        $connection1 = $this->createMock(ConnectionInterface::class);
        $connection2 = $this->createMock(ConnectionInterface::class);

        $this->queue->enqueue(
            new RequestData('req_1', new ServerRequest('GET', '/a'), 1),
            ['connection' => $connection1, 'timestamp' => microtime(true), 'cors_origin' => null],
        );
        $this->queue->enqueue(
            new RequestData('req_2', new ServerRequest('GET', '/b'), 2),
            ['connection' => $connection2, 'timestamp' => microtime(true), 'cors_origin' => null],
        );
        $this->queue->enqueue(
            new RequestData('req_3', new ServerRequest('GET', '/c'), 3),
            ['connection' => $connection1, 'timestamp' => microtime(true), 'cors_origin' => null],
        );

        self::assertSame(3, $this->queue->getPendingRequestCount());

        $this->queue->removeByConnection($connection1);

        self::assertSame(1, $this->queue->getPendingRequestCount());
        self::assertNotNull($this->queue->getContext('req_2'));
        self::assertNull($this->queue->getContext('req_1'));
        self::assertNull($this->queue->getContext('req_3'));
    }

    #[Test]
    public function get_context_returns_correct_data(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $timestamp = microtime(true);
        $requestData = new RequestData('req_42', new ServerRequest('POST', '/api'), 5);

        $this->queue->enqueue($requestData, [
            'connection' => $connection,
            'timestamp' => $timestamp,
            'cors_origin' => 'https://example.com',
        ]);

        $context = $this->queue->getContext('req_42');
        self::assertNotNull($context);
        self::assertSame($connection, $context['connection']);
        self::assertSame($timestamp, $context['timestamp']);
        self::assertSame('https://example.com', $context['cors_origin']);
    }

    #[Test]
    public function get_context_returns_null_for_unknown_id(): void
    {
        self::assertNull($this->queue->getContext('unknown'));
    }

    #[Test]
    public function has_pending_response_tracks_contexts(): void
    {
        self::assertFalse($this->queue->hasPendingResponse());

        $connection = $this->createMock(ConnectionInterface::class);
        $this->queue->enqueue(
            new RequestData('req_0', new ServerRequest('GET', '/'), 1),
            ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null],
        );

        self::assertTrue($this->queue->hasPendingResponse());

        $this->queue->remove('req_0');
        self::assertFalse($this->queue->hasPendingResponse());
    }

    #[Test]
    public function get_pending_request_id_returns_first_key(): void
    {
        self::assertNull($this->queue->getPendingRequestId());

        $connection = $this->createMock(ConnectionInterface::class);
        $this->queue->enqueue(
            new RequestData('req_first', new ServerRequest('GET', '/'), 1),
            ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null],
        );
        $this->queue->enqueue(
            new RequestData('req_second', new ServerRequest('GET', '/'), 2),
            ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null],
        );

        self::assertSame('req_first', $this->queue->getPendingRequestId());
    }

    #[Test]
    public function cleanup_stale_calls_on_stale_for_old_entries(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $staleTimestamp = microtime(true) - 100;

        $this->queue->enqueue(
            new RequestData('req_stale', new ServerRequest('GET', '/'), 1),
            ['connection' => $connection, 'timestamp' => $staleTimestamp, 'cors_origin' => null],
        );

        $staleConnections = [];
        $this->queue->cleanupStale(10, function (ConnectionInterface $conn, string $requestId) use (&$staleConnections): void {
            $staleConnections[$requestId] = $conn;
        });

        self::assertCount(1, $staleConnections);
        self::assertSame($connection, $staleConnections['req_stale']);
        self::assertSame(0, $this->queue->getPendingRequestCount());
    }

    #[Test]
    public function cleanup_stale_preserves_fresh_entries(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $freshTimestamp = microtime(true);

        $this->queue->enqueue(
            new RequestData('req_fresh', new ServerRequest('GET', '/'), 1),
            ['connection' => $connection, 'timestamp' => $freshTimestamp, 'cors_origin' => null],
        );

        $this->queue->cleanupStale(10, function (ConnectionInterface $conn, string $requestId): void {});

        self::assertSame(1, $this->queue->getPendingRequestCount());
    }

    #[Test]
    public function reset_clears_all_state(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $this->queue->enqueue(
            new RequestData('req_0', new ServerRequest('GET', '/'), 1),
            ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null],
        );
        $this->queue->enqueue(
            new RequestData('req_1', new ServerRequest('GET', '/'), 2),
            ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null],
        );

        self::assertTrue($this->queue->hasRequest());
        self::assertSame(2, $this->queue->getPendingRequestCount());

        $this->queue->reset();

        self::assertFalse($this->queue->hasRequest());
        self::assertSame(0, $this->queue->getPendingRequestCount());
        self::assertSame(0, $this->queue->getQueueCount());
    }

    #[Test]
    public function get_queue_count_returns_correct_count(): void
    {
        self::assertSame(0, $this->queue->getQueueCount());

        $connection = $this->createMock(ConnectionInterface::class);
        $this->queue->enqueue(
            new RequestData('req_0', new ServerRequest('GET', '/'), 1),
            ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null],
        );

        self::assertSame(1, $this->queue->getQueueCount());

        $this->queue->dequeue();

        self::assertSame(0, $this->queue->getQueueCount());
    }

    #[Test]
    public function dequeue_skips_orphaned_entries(): void
    {
        $connection1 = $this->createMock(ConnectionInterface::class);
        $connection2 = $this->createMock(ConnectionInterface::class);

        $this->queue->enqueue(
            new RequestData('req_orphan', new ServerRequest('GET', '/'), 1),
            ['connection' => $connection1, 'timestamp' => microtime(true), 'cors_origin' => null],
        );
        $this->queue->enqueue(
            new RequestData('req_valid', new ServerRequest('GET', '/'), 2),
            ['connection' => $connection2, 'timestamp' => microtime(true), 'cors_origin' => null],
        );

        $this->queue->remove('req_orphan');

        $result = $this->queue->dequeue();
        self::assertNotNull($result);
        self::assertSame('req_valid', $result->id);
        self::assertNull($this->queue->dequeue());
    }

    #[Test]
    public function dequeue_skips_orphaned_by_connection(): void
    {
        $connection1 = $this->createMock(ConnectionInterface::class);
        $connection2 = $this->createMock(ConnectionInterface::class);

        $this->queue->enqueue(
            new RequestData('req_1', new ServerRequest('GET', '/'), 1),
            ['connection' => $connection1, 'timestamp' => microtime(true), 'cors_origin' => null],
        );
        $this->queue->enqueue(
            new RequestData('req_2', new ServerRequest('GET', '/'), 2),
            ['connection' => $connection1, 'timestamp' => microtime(true), 'cors_origin' => null],
        );
        $this->queue->enqueue(
            new RequestData('req_3', new ServerRequest('GET', '/'), 3),
            ['connection' => $connection2, 'timestamp' => microtime(true), 'cors_origin' => null],
        );

        $this->queue->removeByConnection($connection1);

        self::assertTrue($this->queue->hasRequest());

        $result = $this->queue->dequeue();
        self::assertNotNull($result);
        self::assertSame('req_3', $result->id);
    }

    #[Test]
    public function has_request_returns_false_when_all_orphaned(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $this->queue->enqueue(
            new RequestData('req_0', new ServerRequest('GET', '/'), 1),
            ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null],
        );

        self::assertTrue($this->queue->hasRequest());

        $this->queue->remove('req_0');

        self::assertFalse($this->queue->hasRequest());
        self::assertNull($this->queue->dequeue());
    }
}
