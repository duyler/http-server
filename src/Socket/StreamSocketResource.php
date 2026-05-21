<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Socket;

use Duyler\HttpServer\Exception\SocketException;
use InvalidArgumentException;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket;
use Throwable;

final class StreamSocketResource implements SocketResourceInterface
{
    private bool $closed = false;

    /**
     * @var Socket|resource|null
     */
    private mixed $resource = null;

    /**
     * @param Socket|resource $resource
     */
    public function __construct(
        mixed $resource,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if (false === is_resource($resource) && false === $resource instanceof Socket) {
            throw new InvalidArgumentException('Invalid socket resource or Socket object');
        }
        $this->resource = $resource;
    }

    public static function configureClient(Socket $client): self
    {
        socket_set_nonblock($client);
        socket_set_option($client, SOL_TCP, TCP_NODELAY, 1);

        return new self($client);
    }

    #[Override]
    public function read(int $length): string|false
    {
        if (false === $this->isValid()) {
            return false;
        }

        if (1 > $length) {
            return false;
        }

        if ($this->resource instanceof Socket) {
            return socket_read($this->resource, $length, PHP_BINARY_READ);
        }

        assert(is_resource($this->resource));
        return fread($this->resource, $length);
    }

    #[Override]
    public function write(string $data): int|false
    {
        if (false === $this->isValid()) {
            return false;
        }

        if ($this->resource instanceof Socket) {
            return socket_write($this->resource, $data, strlen($data));
        }

        assert(is_resource($this->resource));
        $written = fwrite($this->resource, $data);
        if (false !== $written) {
            fflush($this->resource);
        }
        return $written;
    }

    #[Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            if ($this->resource instanceof Socket) {
                socket_close($this->resource);
            } elseif (is_resource($this->resource)) {
                $resource = $this->resource;
                $this->resource = null;
                fclose($resource);
            }
        } catch (Throwable $e) {
            $this->logger->debug('Error closing socket resource', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }

        $this->resource = null;
        $this->closed = true;
    }

    #[Override]
    public function isValid(): bool
    {
        if ($this->closed) {
            return false;
        }

        if ($this->resource instanceof Socket) {
            return true;
        }

        return is_resource($this->resource);
    }

    #[Override]
    public function setBlocking(bool $blocking): void
    {
        if (false === $this->isValid()) {
            throw new SocketException('Cannot set blocking mode on invalid socket');
        }

        if ($this->resource instanceof Socket) {
            $success = $blocking
                ? socket_set_block($this->resource)
                : socket_set_nonblock($this->resource);

            if (false === $success) {
                throw new SocketException(
                    sprintf('Failed to set blocking mode: %s', socket_strerror(socket_last_error($this->resource))),
                );
            }
            return;
        }

        assert(is_resource($this->resource));
        if (false === stream_set_blocking($this->resource, $blocking)) {
            throw new SocketException('Failed to set blocking mode on stream');
        }
    }

    /**
     * @return Socket|resource|null
     */
    #[Override]
    public function getInternalResource(): mixed
    {
        return $this->resource;
    }

    #[Override]
    public function getPeerName(): array|false
    {
        if (false === $this->isValid()) {
            return false;
        }

        if ($this->resource instanceof Socket) {
            $ip = '';
            $port = 0;
            $result = socket_getpeername($this->resource, $ip, $port);

            if (false === $result) {
                return false;
            }

            return ['ip' => $ip, 'port' => $port];
        }

        assert(is_resource($this->resource));
        $address = stream_socket_get_name($this->resource, true);

        if (false === $address) {
            return false;
        }

        $colonPos = strrpos($address, ':');

        if (false === $colonPos) {
            return false;
        }

        $ip = substr($address, 0, $colonPos);
        $port = substr($address, $colonPos + 1);

        if (false === is_numeric($port)) {
            return false;
        }

        return ['ip' => $ip, 'port' => (int) $port];
    }

    #[Override]
    public function exportStream(): mixed
    {
        if (false === $this->isValid()) {
            return false;
        }

        if ($this->resource instanceof Socket) {
            try {
                error_clear_last();
                socket_set_block($this->resource);
            } catch (Throwable) {
                return false;
            }

            $stream = socket_export_stream($this->resource);
            socket_set_nonblock($this->resource);

            return $stream;
        }

        assert(is_resource($this->resource));
        return $this->resource;
    }

    /**
     * Select for readable data on Socket or stream resources
     *
     * @param array<Socket|resource> $resources Resources to check for readability
     * @param int $timeout Timeout in seconds (0 for non-blocking)
     * @return array<Socket|resource>|null Changed resources, or null on error
     */
    public static function select(array $resources, int $timeout = 0): ?array
    {
        if ([] === $resources) {
            return null;
        }

        $sockets = [];
        $streams = [];

        foreach ($resources as $resource) {
            if ($resource instanceof Socket) {
                $sockets[] = $resource;
            } else {
                $streams[] = $resource;
            }
        }

        $ready = [];

        if ([] !== $sockets) {
            $write = null;
            $except = null;
            $changed = socket_select($sockets, $write, $except, $timeout);

            if (false !== $changed && 0 < $changed) {
                foreach ($sockets as $socket) {
                    $ready[] = $socket;
                }
            }
        }

        if ([] !== $streams) {
            $write = null;
            $except = null;
            $changed = stream_select($streams, $write, $except, $timeout);

            if (false !== $changed && 0 < $changed) {
                foreach ($streams as $stream) {
                    $ready[] = $stream;
                }
            }
        }

        if ([] === $ready) {
            return null;
        }

        return $ready;
    }
}
