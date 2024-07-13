<?php

namespace phasync\Server;

use Closure;
use phasync;
use phasync\CancelledException;
use phasync\IOException;

/**
 * A simple TCP/UDP server implementation allowing you to serve multiple
 * ports at the same time.
 */
final class Server
{
    public const PROTO_TCP = 'tcp';
    public const PROTO_UDP = 'udp';
    public const PROTO_UNIX = 'unix';

    /**
     * The socket resource.
     *
     * @var resource
     */
    private mixed $socket;

    /**
     * The protocol specified in the scheme part of the address.
     */
    private string $scheme;

    /**
     * The host as returned from {@see stream_socket_get_name()}.
     */
    private string $host;

    /**
     * The actual port number used by the socket server. For UNIX
     * sockets this will be null.
     */
    private ?int $port;

    /**
     * The address used when creating the server instance.
     */
    private string $address;

    /**
     * The flags used when creating the server instance.
     */
    private int $flags;

    /**
     * The timeout for socket operations.
     */
    private float $timeout;

    /**
     * Create a stream socket server running in a coroutine. To close the server, use
     * {@see phasync::cancel($server)} or throw phasync\CancelledException from the handler.
     *
     * Note that for TCP or UNIX socket servers, to handle many connections concurrently
     * the handler function must launch a coroutine.
     *
     * @param string                                 $address the address to bind the server to
     * @param \Closure<resource|Server,?string,void> $handler closure will be invoked with the client stream if TCP, or the Server instance if UDP
     * @param float|null                             $timeout optional timeout for socket operations
     *
     * @throws \FiberError
     * @throws \Throwable
     */
    public static function serve(string $address, \Closure $handler, ?float $timeout = null): \Fiber
    {
        $fiber = \phasync::go(args: [$address, $handler, $timeout], fn: static function (string $address, $handler, $timeout) {
            $server = new self($address, timeout: $timeout);
            $protocol = $server->getProtocol();
            try {
                if ($protocol === self::PROTO_TCP || $protocol === self::PROTO_UNIX) {
                    while (!$server->isClosed()) {
                        try {
                            $socket = $server->accept($peer);
                            $handler($socket, $peer);
                        } catch (CancelledException) {
                            return;
                        }
                    }
                } elseif ($protocol === self::PROTO_UDP) {
                    return $handler($server, null);
                }
            } finally {
                $server->close();
            }
        });
        if ($fiber->isTerminated()) {
            \phasync::await($fiber);
        }

        return $fiber;
    }

    /**
     * Constructor to initialize the server with the given address and options.
     *
     * @param string              $address the address to bind the server to
     * @param int                 $flags   the flags for stream socket server
     * @param array|resource|null $context optional stream context
     * @param float|null          $timeout optional timeout for socket operations
     *
     * @return void
     */
    public function __construct(string $address, ?int $flags = null, $context = null, ?float $timeout = null)
    {
        $this->address = $address;
        $this->timeout = $timeout ?? \phasync::getDefaultTimeout();

        if (\str_starts_with($address, 'unix://')) {
            $this->scheme = 'unix';
        } else {
            $url = parse_url($address);
            if (!is_array($url) || empty($url['scheme'])) {
                throw new \LogicException("The address must include a scheme. Valid examples: 'tcp://0.0.0.0:8080', 'udp://0.0.0.0:8080', 'unix:///path/to/unix.sock'.");
            }
            // Ensuring that port and host are present for TCP and UDP, not for UNIX
            if (($url['scheme'] === self::PROTO_TCP || $url['scheme'] === self::PROTO_UDP)
                && (empty($url['port']) || empty($url['host']))) {
                throw new \LogicException("TCP and UDP addresses must include a host and port. Valid example: 'tcp://0.0.0.0:8080'.");
            }
            switch ($url['scheme']) {
                case self::PROTO_TCP:
                case self::PROTO_UDP: break;
                default: throw new \LogicException('Scheme '.$url['scheme']." not supported. Use 'unix://', 'udp://' or 'tcp://'.");
            }

            $this->scheme = $url['scheme'];
        }

        if ($flags === null) {
            if ($this->scheme === 'udp') {
                $flags = STREAM_SERVER_BIND;
            } else {
                $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
            }
        }
        $this->flags = $flags;

        if ($context === null) {
            $context = [];
        } elseif (is_resource($context)) {
            $context = stream_context_get_options($context);
        }

        $context = $this->setDefaultContextOptions($context);

        $context = \stream_context_create($context);

        $socket = \stream_socket_server($address, $error_code, $error_message, $flags, $context);
        if (!$socket) {
            throw new IOException($error_message, $error_code);
        }
        \stream_set_blocking($socket, false);

        if ($this->scheme !== 'unix') {
            $name = \stream_socket_get_name($socket, false);
            [$this->host, $this->port] = \explode(':', $name);
        } else {
            $this->host = '';
            $this->port = null;
        }

        $this->socket = $socket;
    }

    /**
     * Check if the server is closed.
     */
    public function isClosed(): bool
    {
        return !\is_resource($this->socket);
    }

    /**
     * Close the server socket.
     */
    public function close(): void
    {
        if (\is_resource($this->socket)) {
            \fclose($this->socket);
        }
    }

    public function getFlags(): int
    {
        return $this->flags;
    }

    /**
     * Get the address the socket is connected to.
     *
     * Example: 'tcp://123.123.123.123:48273'.
     */
    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * Get the host part of the address the server uses.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Get the port number that the server uses.
     *
     * @return ?int
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * Get the protocol used by this server.
     *
     * @return self::PROTO_TCP|self::PROTO_UDP|self:PROTO_UNIX
     */
    public function getProtocol(): string
    {
        return $this->scheme;
    }

    /**
     * Accept a TCP connection. The returned stream resource can be used as normally
     * with the standard PHP library of functions fread(), fwrite() etc, but it will
     * trigger context switching from the coroutine if reading or writing would block.
     *
     * @see https://www.php.net/manual/en/function.stream-socket-accept.php
     *
     * @return false|resource
     *
     * @throws \FiberError
     * @throws \Throwable
     */
    public function accept(?string &$peer_name = null)
    {
        \phasync::readable($this->socket, $this->timeout);
        $result = \stream_socket_accept($this->socket, 0, $peer_name);
        if (!$result) {
            return false;
        }
        \stream_set_blocking($result, false);
        \stream_set_write_buffer($result, 1024 * 512);
        \stream_set_read_buffer($result, 65536);
        \stream_set_chunk_size($result, 65536);

        return $result;
    }

    /**
     * Sends a message to a socket, whether it is connected or not.
     *
     * @see https://www.php.net/manual/en/function.stream-socket-sendto.php
     *
     * @param string $data    the data to be sent
     * @param int    $flags   The value can be {@see STREAM_OOB}
     * @param string $address if specified, it must be in dotted quad (or [ipv6]) format
     *
     * @throws \FiberError
     * @throws \Throwable
     */
    public function sendto(string $data, int $flags = 0, string $address = ''): int|false
    {
        \phasync::writable($this->socket, $this->timeout);

        return \stream_socket_sendto($this->socket, $data, $flags, $address);
    }

    /**
     * Receives data from a socket, connected or not.
     *
     * @see https://www.php.net/manual/en/function.stream-socket-recvfrom.php
     *
     * @param int $length the number of bytes to receive from the socket
     * @param int $flags  Can be either {@see STREAM_OOB} or {@see STREAM_PEEK}
     *
     * @return string|false if address is provided it will be populated with the address of the remote socket
     *
     * @throws \FiberError
     * @throws \Throwable
     */
    public function recvfrom(int $length, int $flags = 0, ?string &$address = null): string|false
    {
        \phasync::readable($this->socket, $this->timeout);

        return \stream_socket_recvfrom($this->socket, $length, $flags, $address);
    }

    /**
     * Set default context options.
     *
     * @param array $context the context options to set defaults for
     *
     * @return array the context options with defaults set
     */
    private function setDefaultContextOptions(array $context): array
    {
        if (empty($context['socket']['backlog'])) {
            // This setting allows the socket to hold up to 511 pending
            // connections.
            $context['socket']['backlog'] = 511;
        }

        if ($this->scheme !== self::PROTO_UNIX) {
            if (empty($context['socket']['so_reuseport'])) {
                // This setting allows other processes of the same user to
                // also accept connections on this socket.
                $context['socket']['so_reuseport'] = true;
            }
        } else {
            unset($context['socket']['so_reuseport']);
        }

        if ($this->scheme === self::PROTO_TCP) {
            if (empty($context['socket']['tcp_nodelay'])) {
                // This setting disables Nagle's algorithm, which is generally
                // a socket-level buffer which prevents sending of small packets
                // immediately. This is disabled by default since we assume that
                // by sending a small packet, the developer intends it to be
                // delivered with minimal latency.
                $context['socket']['tcp_nodelay'] = true;
            }
        } else {
            unset($context['socket']['tcp_nodelay']);
        }

        return $context;
    }
}
