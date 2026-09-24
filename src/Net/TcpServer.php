<?php

namespace phasync\Net;

use Generator;
use phasync;
use phasync\IOException;

/**
 * A TCP or Unix domain socket server listening on one address.
 *
 * Modeled on Go's net.Listener: one server is one listening socket and one accept loop.
 * To listen on several addresses, create one server per address and run each accept loop
 * in its own coroutine. To handle all of them in one place, have each loop write its
 * connections to a shared channel.
 *
 * Accepted connections are plain non-blocking stream resources. Wait with
 * phasync::readable() / phasync::writable() before reading or writing, or load the
 * phasync extension (see phasync\try_enable_ext()) to make plain fread() / fwrite()
 * suspend the coroutine by themselves.
 *
 * Example:
 * ```php
 * phasync::run(function () {
 *     $server = new TcpServer('0.0.0.0:8080');
 *     foreach ($server->accept() as $peer => $stream) {
 *         phasync::go(function () use ($stream) {
 *             $request = fread(phasync::readable($stream), 65536);
 *             fwrite(phasync::writable($stream), "HTTP/1.0 200 OK\r\n\r\nHello\n");
 *             fclose($stream);
 *         });
 *     }
 * });
 * ```
 */
final class TcpServer
{
    /** @var resource|null */
    private $socket;

    private string $address;

    /** Path of the socket file for a unix:// server, removed again on close(). */
    private ?string $unixPath = null;

    /**
     * @param string $address Address to listen on, such as '0.0.0.0:8080', '[::]:8080' or
     *                        'unix:///run/app.sock'. Port 0 picks a free port; see getAddress().
     * @param array  $context Stream context options. Defaults: backlog 65535 (the kernel caps
     *                        it at its own limit, net.core.somaxconn on Linux), and for TCP
     *                        so_reuseport and tcp_nodelay enabled.
     *
     * @throws \RuntimeException if the address cannot be bound
     */
    public function __construct(string $address, array $context = [])
    {
        if (!\str_contains($address, '://')) {
            $address = 'tcp://' . $address;
        }
        $protocol = \strstr($address, '://', true);
        $context  = self::applyDefaultContext($context, $protocol);

        $warning = null;
        \set_error_handler(static function (int $code, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            $socket = \stream_socket_server(
                $address,
                $errno,
                $errstr,
                \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
                \stream_context_create($context)
            );
        } finally {
            \restore_error_handler();
        }
        if (!$socket) {
            throw new \RuntimeException("Failed to bind to $address: " . ($errstr ?: $warning ?? 'unknown error'), $errno);
        }

        \stream_set_blocking($socket, false);
        $this->socket  = $socket;
        $this->address = (string) \stream_socket_get_name($socket, false);
        if ('unix' === $protocol) {
            $this->unixPath = \substr($address, \strlen('unix://'));
        }
    }

    /**
     * Accept connections as they arrive.
     *
     * Every connection already waiting in the kernel's accept queue is taken without
     * waiting for the event loop, so a burst of connections is admitted at once. The loop
     * ends when the server is closed.
     *
     * @return Generator<string, resource> peer address => connection stream
     */
    public function accept(): Generator
    {
        while (\is_resource($this->socket)) {
            if (!$this->hasPendingConnection()) {
                try {
                    phasync::readable($this->socket, \PHP_FLOAT_MAX);
                } catch (IOException $e) {
                    if (\is_resource($this->socket)) {
                        throw $e;
                    }

                    return; // closed while waiting
                }
                if (!\is_resource($this->socket)) {
                    return;
                }
            }

            // Can still fail when several processes share this socket (so_reuseport) and
            // another one took the connection first.
            $stream = @\stream_socket_accept($this->socket, 0, $peer);
            if (false === $stream) {
                continue;
            }
            \stream_set_blocking($stream, false);

            yield (string) $peer => $stream;
        }
    }

    /**
     * Stop listening. An accept() loop waiting for a connection ends. A Unix socket's file
     * is removed.
     */
    public function close(): void
    {
        if (\is_resource($this->socket)) {
            \fclose($this->socket);
        }
        $this->socket = null;
        if (null !== $this->unixPath && \file_exists($this->unixPath)) {
            @\unlink($this->unixPath);
        }
        $this->unixPath = null;
    }

    public function isClosed(): bool
    {
        return !\is_resource($this->socket);
    }

    /**
     * The address the server is bound to, with the real port when it was created with
     * port 0 (for example '127.0.0.1:43127'), or the socket path for a Unix socket.
     */
    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * Whether a connection is waiting in the accept queue right now, without blocking.
     * Checking first avoids calling stream_socket_accept() on an empty queue, which always
     * emits a warning. Uses the phasync extension's stream_select() when loaded, since the
     * native one fails for file descriptor numbers at or above FD_SETSIZE.
     */
    private function hasPendingConnection(): bool
    {
        $read   = [$this->socket];
        $write  = null;
        $except = null;
        $ready  = \function_exists('phasync\ext\stream_select')
            ? \phasync\ext\stream_select($read, $write, $except, 0, 0)
            : @\stream_select($read, $write, $except, 0, 0);

        return $ready > 0;
    }

    private static function applyDefaultContext(array $context, string $protocol): array
    {
        $context['socket']['backlog'] ??= 65535;

        if ('unix' === $protocol) {
            // Unix sockets don't support these options
            unset($context['socket']['so_reuseport'], $context['socket']['tcp_nodelay']);
        } else {
            $context['socket']['so_reuseport'] ??= true;
            $context['socket']['tcp_nodelay']  ??= true;
        }

        return $context;
    }
}
