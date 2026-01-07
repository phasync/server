<?php

namespace phasync\Net;

use phasync;
use Generator;

/**
 * A simple UDP server for receiving and sending datagrams.
 *
 * Example usage:
 * ```php
 * $server = new UdpServer('0.0.0.0:9000');
 * foreach ($server->receive() as $addr => $data) {
 *     $server->send($addr, "Echo: $data");
 * }
 * ```
 */
final class UdpServer
{
    /** @var resource[] */
    private array $sockets = [];

    /** @var string[] Map of socket id to bound address */
    private array $addresses = [];

    private bool $closed = false;

    /**
     * @param string|string[] $addresses Address(es) to listen on (e.g., '0.0.0.0:9000')
     * @param array $context Optional stream context options
     */
    public function __construct(string|array $addresses, array $context = [])
    {
        if (is_string($addresses)) {
            $addresses = [$addresses];
        }

        foreach ($addresses as $address) {
            // Add udp:// if no protocol specified
            if (!str_contains($address, '://')) {
                $address = 'udp://' . $address;
            }

            $socketContext = $this->applyDefaultContext($context);
            $streamContext = stream_context_create($socketContext);

            // UDP uses BIND only, no LISTEN
            $socket = @stream_socket_server(
                $address,
                $errno,
                $errstr,
                STREAM_SERVER_BIND,
                $streamContext
            );

            if (!$socket) {
                $this->close();
                throw new \RuntimeException("Failed to bind to $address: $errstr", $errno);
            }

            stream_set_blocking($socket, false);
            stream_set_read_buffer($socket, 0);
            $id = (int) $socket;
            $this->sockets[$id] = $socket;
            $this->addresses[$id] = stream_socket_get_name($socket, false);
        }
    }

    /**
     * Receive datagrams from clients.
     *
     * @param int $maxLength Maximum bytes to receive per datagram
     * @return Generator<string, array{0: string, 1: resource}> Yields peer address => [data, socket]
     */
    public function receive(int $maxLength = 65536): Generator
    {
        while (!$this->closed && $this->sockets) {
            $ready = phasync::select([], read: array_values($this->sockets));

            if ($this->closed || !$ready) {
                break;
            }

            $data = @stream_socket_recvfrom($ready, $maxLength, 0, $peer);
            if ($data !== false && $data !== '') {
                // Yield socket too so replies use the correct interface
                yield $peer => [$data, $ready];
            }
        }
    }

    /**
     * Send a datagram to a specific address (for initiating sends).
     *
     * Note: When replying to received packets, prefer using stream_socket_sendto()
     * with the socket yielded from receive() to ensure the reply comes from the
     * correct interface.
     *
     * @param string $address The destination address (e.g., '127.0.0.1:12345')
     * @param string $data The data to send
     * @param int $flags Optional flags (STREAM_OOB)
     * @return int|false Number of bytes sent or false on failure
     */
    public function send(string $address, string $data, int $flags = 0): int|false
    {
        if ($this->closed || !$this->sockets) {
            return false;
        }

        $socket = reset($this->sockets);
        phasync::writable($socket);

        return stream_socket_sendto($socket, $data, $flags, $address);
    }

    /**
     * Close all sockets.
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
     * Apply default context options for UDP servers.
     */
    private function applyDefaultContext(array $context): array
    {
        if (!isset($context['socket']['backlog'])) {
            $context['socket']['backlog'] = 511;
        }
        if (!isset($context['socket']['so_reuseport'])) {
            $context['socket']['so_reuseport'] = true;
        }
        return $context;
    }
}
