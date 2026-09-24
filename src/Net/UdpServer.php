<?php

namespace phasync\Net;

use Generator;
use phasync;
use phasync\IOException;

/**
 * A UDP server bound to one address.
 *
 * One server is one socket, like TcpServer. To serve several addresses, create one server
 * per address and run each receive loop in its own coroutine.
 *
 * Example:
 * ```php
 * phasync::run(function () {
 *     $server = new UdpServer('0.0.0.0:9000');
 *     foreach ($server->receive() as $peer => $data) {
 *         $server->send($peer, "Echo: $data");
 *     }
 * });
 * ```
 */
final class UdpServer
{
    /** @var resource|null */
    private $socket;

    private string $address;

    /**
     * @param string $address Address to bind, such as '0.0.0.0:9000'. Port 0 picks a free
     *                        port; see getAddress().
     * @param array  $context Stream context options. Default: so_reuseport enabled.
     *
     * @throws \RuntimeException if the address cannot be bound
     */
    public function __construct(string $address, array $context = [])
    {
        if (!\str_contains($address, '://')) {
            $address = 'udp://' . $address;
        }
        $context['socket']['so_reuseport'] ??= true;

        $warning = null;
        \set_error_handler(static function (int $code, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            // UDP binds only, there is no listen step
            $socket = \stream_socket_server($address, $errno, $errstr, \STREAM_SERVER_BIND, \stream_context_create($context));
        } finally {
            \restore_error_handler();
        }
        if (!$socket) {
            throw new \RuntimeException("Failed to bind to $address: " . ($errstr ?: $warning ?? 'unknown error'), $errno);
        }

        \stream_set_blocking($socket, false);
        $this->socket  = $socket;
        $this->address = (string) \stream_socket_get_name($socket, false);
    }

    /**
     * Receive datagrams as they arrive.
     *
     * Every datagram already waiting is taken without waiting for the event loop. The loop
     * ends when the server is closed.
     *
     * @param int $maxLength Maximum bytes per datagram; longer datagrams are truncated
     *
     * @return Generator<string, string> peer address => datagram
     */
    public function receive(int $maxLength = 65536): Generator
    {
        while (\is_resource($this->socket)) {
            // Returns false, without a warning, when nothing is waiting.
            $data = \stream_socket_recvfrom($this->socket, $maxLength, 0, $peer);
            if (false === $data || null === $peer) {
                try {
                    phasync::readable($this->socket, \PHP_FLOAT_MAX);
                } catch (IOException $e) {
                    if (\is_resource($this->socket)) {
                        throw $e;
                    }

                    return; // closed while waiting
                }
                continue;
            }

            yield $peer => $data;
        }
    }

    /**
     * Send a datagram to an address, such as a peer address yielded by receive().
     *
     * @param int $flags 0 or STREAM_OOB
     *
     * @return int|false bytes sent, or false on failure or when the server is closed
     */
    public function send(string $address, string $data, int $flags = 0): int|false
    {
        if (!\is_resource($this->socket)) {
            return false;
        }
        phasync::writable($this->socket, \PHP_FLOAT_MAX);

        return \stream_socket_sendto($this->socket, $data, $flags, $address);
    }

    /**
     * Close the socket. A receive() loop waiting for a datagram ends.
     */
    public function close(): void
    {
        if (\is_resource($this->socket)) {
            \fclose($this->socket);
        }
        $this->socket = null;
    }

    public function isClosed(): bool
    {
        return !\is_resource($this->socket);
    }

    /**
     * The address the server is bound to, with the real port when it was created with
     * port 0 (for example '127.0.0.1:43127').
     */
    public function getAddress(): string
    {
        return $this->address;
    }
}
