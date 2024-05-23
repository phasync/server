<?php
namespace phasync\Server;

use Closure;
use Fiber;
use FiberError;
use InvalidArgumentException;
use LogicException;
use phasync;
use phasync\CancelledException;
use phasync\IOException;
use Throwable;

/**
 * A simple TCP/UDP server implementation allowing you to serve multiple
 * ports on the same time.
 * 
 * @package phasync\Server
 */
final class Server {

    /**
     * Create a stream socket server running in a coroutine. To close the server, use
     * {@see phasync::cancel($server)} or throw phasync\CancelledException from the handler.
     * 
     * @param string $address 
     * @param Closure<resource|Server,?string,void> $handler Closure will be invoked with the client stream if TCP, or the Server instance if UDP.
     * @return Fiber 
     * @throws FiberError 
     * @throws Throwable 
     */
    public static function serve(string $address, Closure $handler): Fiber {
        return phasync::run(args: [$address, $handler], fn: static function(string $address, $handler) {
            $server = new self($address);
            if ($server->getProtocol() === self::PROTO_TCP) {
                do {
                    try {
                        $socket = $server->accept($peer);
                        phasync::go(args: [$socket, $peer], fn: $handler);
                    } catch (CancelledException $e) {
                        return;
                    }
                } while (true);
            } else {
                return $handler($server, null);
            }
        });
    }

    public const PROTO_TCP = 'tcp';
    public const PROTO_UDP = 'udp';

    /**
     * The socket resource
     * 
     * @var resource
     */
    private mixed $socket;

    /**
     * The protocol specified in the scheme part of the address.
     * 
     * @var string
     */
    private string $scheme;

    /**
     * The host as returned from {@see stream_socket_get_name()}
     * 
     * @var string
     */
    private string $host;

    /**
     * The host specified in the host part of the address.
     * 
     * @var string
     */
    private string $requestedHost;

    /**
     * The actual port number used by the socket server.
     * 
     * @var int
     */
    private int $port;

    /**
     * The requested port number in the port part of the address.
     * 
     * @var string
     */
    private string $requestedPort;

    /**
     * This class constructor generally accepts the same arguments as \stream_socket_server()
     * in the PHP standard library.
     * 
     * @see https://www.php.net/manual/en/function.stream-socket-server.php
     * @param string $address 
     * @param int $errorCode 
     * @param string|null $errorMessage 
     * @param int $flags 
     * @param array|resource|null $context 
     * @return void 
     */
    public function __construct(string $address, int $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context = null) {                
        $url = parse_url($address);
        if (!is_array($url) || empty($url['scheme']) || empty($url['port']) || empty($url['host'])) {
            throw new LogicException("The address is in an incorrect format. Valid example: 'tcp://0.0.0.0:8080' or 'udp://0.0.0.0:8080'.");
        }
        switch ($url['scheme']) {
            case self::PROTO_TCP:
            case self::PROTO_UDP: break;
            default: throw new LogicException("Scheme " . $url['scheme'] . " not supported. Use 'udp://' or 'tcp://'.");
        }
        $this->scheme = $url['scheme'];
        $this->requestedHost = $url['host'];
        $this->requestedPort = (int) ($url['port'] ?: 0);

        if ($context === null) {
            $context = [];
        } elseif (is_resource($context)) {
            $context = stream_context_get_options($context);
        }

        if (empty($context['socket'])) {
            $context['socket'] = [
                'backlog' => 511,       // Allow PHP to hold a number of sockets while doing other stuff
                'so_reuseport' => true, // See README.md
                'tcp_nodelay' => true,  // See README.md
            ];
        }

        $context = \stream_context_create($context);

        $socket = \stream_socket_server($address, $error_code, $error_message, $flags, $context);
        if (!$socket) {
            throw new IOException($error_message, $error_code);
        }

        $this->socket = $socket;

        \stream_set_blocking($this->socket, false);
        $name = \stream_socket_get_name($this->socket, false);
        [$this->host, $this->port] = \explode(":", $name);
    }

    /**
     * Get the address the socket is connected to. This may be different that the
     * address used to create the server instance, particularly if port 0 was specified.
     * 
     * Example: 'tcp://123.123.123.123:48273'.
     * 
     * @return string 
     */
    public function getAddress(): string {
        return $this->scheme . '://' . $this->requestedHost . ':' . $this->port;
    }

    /**
     * Get the host part of the address the server uses.
     * 
     * @return string 
     */
    public function getHost(): string {
        return $this->host;
    }

    /**
     * Get the port number that the server uses.
     * 
     * @return int 
     */
    public function getPort(): int {
        return $this->port;
    }

    /**
     * Get the protocol used by this server
     * 
     * @return self::TCP|self::UDP
     */
    public function getProtocol(): string {
        return $this->scheme;
    }

    /**
     * Accept a TCP connection. The returned stream resource can be used as normally
     * with the standard PHP library of functions fread(), fwrite() etc, but it will
     * trigger context switching from the coroutine if reading or writing would block.
     * 
     * @see https://www.php.net/manual/en/function.stream-socket-accept.php
     * @param string|null $peer_name 
     * @return false|resource 
     * @throws FiberError 
     * @throws Throwable 
     */
    public function accept(string &$peer_name = null) {
        phasync::readable($this->socket);
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
     * Sends a message to a socket, whether it is connected or not
     * 
     * @see https://www.php.net/manual/en/function.stream-socket-sendto.php
     * @param string $data The data to be sent.
     * @param int $flags The value can be {@see STREAM_OOB}
     * @param string $address If specified, it must be in dotted quad (or [ipv6]) format.
     * @return int|false 
     * @throws FiberError 
     * @throws Throwable 
     */
    public function sendto(string $data, int $flags = 0, string $address = ""): int|false {
        phasync::writable($this->socket);
        return \stream_socket_sendto($this->socket, $data, $flags, $address);
    }

    /**
     * Receives data from a socket, connected or not.
     * 
     * @see https://www.php.net/manual/en/function.stream-socket-recvfrom.php
     * @param int $length The number of bytes to receive from the socket.
     * @param int $flags Can be either {@see STREAM_OOB} or {@see STREAM_PEEK}
     * @param null|string $address 
     * @return string|false If address is provided it will be populated with the address of the remote socket.
     * @throws FiberError 
     * @throws Throwable 
     */
    public function recvfrom(int $length, int $flags = 0, ?string &$address = null): string|false {
        phasync::readable($this->socket);
        return \stream_socket_recvfrom($this->socket, $length, $flags, $address);
    }
}
