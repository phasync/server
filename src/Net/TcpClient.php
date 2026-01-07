<?php

namespace phasync\Net;

use phasync;
use phasync\Internal\AsyncStream;
use RuntimeException;

/**
 * A non-blocking TCP client for phasync.
 *
 * Handles asynchronous connection establishment without blocking the event loop.
 * Returns AsyncStream-wrapped connections for transparent async I/O.
 *
 * Example usage:
 * ```php
 * $conn = TcpClient::connect('example.com:80');
 * fwrite($conn, "GET / HTTP/1.0\r\nHost: example.com\r\n\r\n");
 * $response = stream_get_contents($conn);
 * fclose($conn);
 * ```
 */
final class TcpClient
{
    /**
     * Connect to a TCP server asynchronously.
     *
     * @param string $address Address to connect to (e.g., '127.0.0.1:80', 'example.com:443')
     * @param float $timeout Connection timeout in seconds
     * @param array $context Stream context options
     * @return resource AsyncStream-wrapped connection
     * @throws RuntimeException If connection fails or times out
     */
    public static function connect(string $address, float $timeout = 30, array $context = []): mixed
    {
        if (!str_contains($address, '://')) {
            $address = 'tcp://' . $address;
        }

        // Async DNS resolution for hostnames
        $parsed = parse_url($address);
        if (isset($parsed['host']) && !filter_var($parsed['host'], FILTER_VALIDATE_IP)) {
            $ip = Dns::resolve($parsed['host'], $timeout);
            if ($ip === null) {
                throw new RuntimeException("DNS resolution failed for {$parsed['host']}");
            }
            $port = $parsed['port'] ?? 80;
            $address = "tcp://$ip:$port";
        }

        // STREAM_CLIENT_ASYNC_CONNECT returns immediately, handshake happens async
        $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;

        $context = array_replace_recursive([
            'socket' => [
                'tcp_nodelay' => true,
            ]
        ], $context);

        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $timeout,
            $flags,
            stream_context_create($context)
        );

        if (!$socket) {
            throw new RuntimeException("Connection failed to $address: $errstr", $errno);
        }

        stream_set_blocking($socket, false);

        try {
            // Wait for socket to become writable (SYN-ACK received or RST)
            phasync::writable($socket, $timeout);

            // Verify actual connection - peer name check catches refused/reset
            if (@stream_socket_get_name($socket, true) === false) {
                throw new RuntimeException("Connection refused or reset: $address");
            }
        } catch (\Throwable $e) {
            fclose($socket);
            throw $e;
        }

        stream_set_read_buffer($socket, 0);
        stream_set_write_buffer($socket, 0);
        stream_set_chunk_size($socket, 65536);

        return AsyncStream::wrap($socket);
    }

    /**
     * Connect to a Unix domain socket.
     *
     * @param string $path Socket path (e.g., '/var/run/app.sock')
     * @param float $timeout Connection timeout in seconds
     * @param array $context Stream context options
     * @return resource AsyncStream-wrapped connection
     * @throws RuntimeException If connection fails
     */
    public static function connectUnix(string $path, float $timeout = 30, array $context = []): mixed
    {
        if (!str_contains($path, '://')) {
            $path = 'unix://' . $path;
        }

        return self::connect($path, $timeout, $context);
    }
}
