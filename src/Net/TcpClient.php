<?php

namespace phasync\Net;

use phasync;
use RuntimeException;

/**
 * A non-blocking TCP client for phasync.
 *
 * Connects without blocking the event loop, including DNS resolution. Returns a plain
 * non-blocking stream: wait with phasync::readable() / phasync::writable() before reading
 * or writing.
 *
 * Example usage:
 * ```php
 * $conn = TcpClient::connect('example.com:80');
 * fwrite(phasync::writable($conn), "GET / HTTP/1.0\r\nHost: example.com\r\n\r\n");
 * $response = fread(phasync::readable($conn), 65536);
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
     * @return resource non-blocking connection stream
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

        return $socket;
    }

    /**
     * Connect to a Unix domain socket.
     *
     * @param string $path Socket path (e.g., '/var/run/app.sock')
     * @param float $timeout Connection timeout in seconds
     * @param array $context Stream context options
     * @return resource non-blocking connection stream
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
