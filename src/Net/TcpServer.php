<?php

namespace phasync\Net;

use phasync;
use phasync\Internal\AsyncStream;
use Generator;

/**
 * A simple TCP server that accepts connections on one or more addresses.
 *
 * Example usage:
 * ```php
 * $server = new TcpServer('0.0.0.0:8080');
 * foreach ($server->accept() as $addr => $stream) {
 *     phasync::go(function() use ($stream, $addr) {
 *         fwrite(phasync::writable($stream), "Hello $addr\n");
 *         fclose($stream);
 *     });
 * }
 * ```
 */
final class TcpServer
{
    /** @var resource[] */
    private array $sockets = [];

    /** @var string[] Map of socket id to bound address */
    private array $addresses = [];

    private bool $closed = false;
    private bool $wrapStreams;
    private int $readBuffer;
    private int $writeBuffer;

    /**
     * @param string|string[] $addresses Address(es) to listen on (e.g., '0.0.0.0:8080' or ['0.0.0.0:80', '0.0.0.0:443'])
     * @param array $context Optional stream context options
     * @param bool $wrapStreams Wrap accepted streams with AsyncStream for transparent async I/O (default: true)
     * @param int $readBuffer Read buffer size in bytes (0 for unbuffered)
     * @param int $writeBuffer Write buffer size in bytes (0 for unbuffered)
     */
    public function __construct(
        string|array $addresses,
        array $context = [],
        bool $wrapStreams = true,
        int $readBuffer = 0,
        int $writeBuffer = 0
    ) {
        $this->wrapStreams = $wrapStreams;
        $this->readBuffer = $readBuffer;
        $this->writeBuffer = $writeBuffer;

        if (is_string($addresses)) {
            $addresses = [$addresses];
        }

        foreach ($addresses as $address) {
            // Add tcp:// if no protocol specified
            if (!str_contains($address, '://')) {
                $address = 'tcp://' . $address;
            }

            // Detect protocol for context options
            $protocol = strstr($address, '://', true);
            $socketContext = $this->applyDefaultContext($context, $protocol);
            $streamContext = stream_context_create($socketContext);

            $socket = @stream_socket_server(
                $address,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                $streamContext
            );

            if (!$socket) {
                $this->close();
                throw new \RuntimeException("Failed to bind to $address: $errstr", $errno);
            }

            stream_set_blocking($socket, false);
            $id = (int) $socket;
            $this->sockets[$id] = $socket;
            $this->addresses[$id] = stream_socket_get_name($socket, false);
        }
    }

    /**
     * Accept incoming connections.
     *
     * @return Generator<string, resource> Yields peer address => stream
     */
    public function accept(): Generator
    {
        // Optimize for single-socket case (most common)
        if (count($this->sockets) === 1) {
            $socket = reset($this->sockets);
            while (!$this->closed && is_resource($socket)) {
                phasync::readable($socket, PHP_FLOAT_MAX);

                if ($this->closed) {
                    break;
                }

                $stream = @stream_socket_accept($socket, 0, $peer);
                if ($stream) {
                    stream_set_blocking($stream, false);
                    stream_set_read_buffer($stream, $this->readBuffer);
                    stream_set_write_buffer($stream, $this->writeBuffer);
                    stream_set_chunk_size($stream, 65536);
                    yield $peer => $this->wrapStreams ? AsyncStream::wrap($stream) : $stream;
                }
            }
            return;
        }

        // Multi-socket case: use select
        while (!$this->closed && $this->sockets) {
            $ready = phasync::select([], read: array_values($this->sockets), timeout: PHP_FLOAT_MAX);

            if ($this->closed || !$ready) {
                break;
            }

            $stream = @stream_socket_accept($ready, 0, $peer);
            if ($stream) {
                stream_set_blocking($stream, false);
                stream_set_read_buffer($stream, $this->readBuffer);
                stream_set_write_buffer($stream, $this->writeBuffer);
                stream_set_chunk_size($stream, 65536);
                yield $peer => $this->wrapStreams ? AsyncStream::wrap($stream) : $stream;
            }
        }
    }

    /**
     * Close all listening sockets.
     */
    public function close(): void
    {
        $this->closed = true;
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->sockets = [];
    }

    /**
     * Check if the server is closed.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Get the addresses the server is listening on.
     *
     * @return string[]
     */
    public function getAddresses(): array
    {
        return array_values($this->addresses);
    }

    /**
     * Apply default context options based on protocol.
     */
    private function applyDefaultContext(array $context, string $protocol): array
    {
        if (!isset($context['socket']['backlog'])) {
            $context['socket']['backlog'] = 511;
        }

        if ($protocol === 'unix') {
            // Unix sockets don't support these options
            unset($context['socket']['so_reuseport']);
            unset($context['socket']['tcp_nodelay']);
        } else {
            if (!isset($context['socket']['so_reuseport'])) {
                $context['socket']['so_reuseport'] = true;
            }
            if (!isset($context['socket']['tcp_nodelay'])) {
                $context['socket']['tcp_nodelay'] = true;
            }
        }

        return $context;
    }
}
