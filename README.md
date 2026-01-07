# phasync/server

> **Note:** This package will be renamed to `phasync/net` in a future release.

Efficient TCP and UDP networking for PHP using phasync coroutines.

All stream resources are wrapped with `AsyncStream`, providing transparent async I/O - no need to call `phasync::readable()` or `phasync::writable()` manually.

## Installation

```bash
composer require phasync/server
```

## TcpServer

Accept TCP connections using a simple iterator pattern:

```php
use phasync\Net\TcpServer;

phasync::run(function() {
    $server = new TcpServer('0.0.0.0:8080');

    foreach ($server->accept() as $peer => $stream) {
        phasync::go(function() use ($stream, $peer) {
            $request = fread($stream, 65536);

            fwrite($stream,
                "HTTP/1.1 200 OK\r\n" .
                "Connection: close\r\n" .
                "Content-Length: 13\r\n" .
                "\r\n" .
                "Hello, $peer!"
            );
            fclose($stream);
        });
    }
});
```

### Multiple Listen Addresses

Listen on multiple ports or interfaces simultaneously:

```php
$server = new TcpServer(['0.0.0.0:80', '0.0.0.0:443']);

foreach ($server->accept() as $peer => $stream) {
    // Connections from both ports arrive here
}
```

### Unix Domain Sockets

```php
$server = new TcpServer('unix:///var/run/myapp.sock');

foreach ($server->accept() as $peer => $stream) {
    // Handle connection
}
```

### API

```php
// Constructor
new TcpServer(string|array $addresses, array $context = [])

// Accept connections (generator)
$server->accept(): Generator<string, resource>  // yields peer => stream

// Management
$server->close(): void
$server->isClosed(): bool
$server->getAddresses(): array
```

## Dns

Async DNS resolution (used automatically by TcpClient):

```php
use phasync\Net\Dns;
use phasync\Net\DnsRecordType;

phasync::run(function() {
    // Default: tries A (IPv4) first, then AAAA (IPv6)
    $ip = Dns::resolve('example.com');

    // Explicit IPv4 only
    $ipv4 = Dns::resolve('example.com', DnsRecordType::A);

    // Explicit IPv6 only
    $ipv6 = Dns::resolve('example.com', DnsRecordType::AAAA);
});
```

Features:
- Non-blocking UDP queries to system nameserver
- IPv4 (A) and IPv6 (AAAA) support
- Reads `/etc/hosts` first (both IPv4 and IPv6 entries)
- Caches results using DNS TTL
- Falls back to 8.8.8.8 if no nameserver configured

### API

```php
Dns::resolve(string $hostname, DnsRecordType $type = DnsRecordType::ANY, float $timeout = 2.0): ?string
Dns::clearCache(): void

// DnsRecordType enum
DnsRecordType::A     // IPv4
DnsRecordType::AAAA  // IPv6
DnsRecordType::ANY   // Try A first, then AAAA (default)
```

## TcpClient

Connect to TCP servers asynchronously (with async DNS):

```php
use phasync\Net\TcpClient;

phasync::run(function() {
    $conn = TcpClient::connect('example.com:80');

    fwrite($conn, "GET / HTTP/1.0\r\nHost: example.com\r\n\r\n");
    $response = stream_get_contents($conn);
    fclose($conn);

    echo $response;
});
```

### Unix Domain Sockets

```php
$conn = TcpClient::connectUnix('/var/run/app.sock');
```

### API

```php
// Connect to TCP server
TcpClient::connect(string $address, float $timeout = 30, array $context = []): resource

// Connect to Unix socket
TcpClient::connectUnix(string $path, float $timeout = 30, array $context = []): resource
```

## UdpServer

Receive UDP datagrams with correct reply routing:

```php
use phasync\Net\UdpServer;

phasync::run(function() {
    $server = new UdpServer('0.0.0.0:9000');

    foreach ($server->receive() as $peer => [$data, $socket]) {
        // Reply using the same socket (correct interface routing)
        stream_socket_sendto($socket, "Echo: $data", 0, $peer);
    }
});
```

### Multiple Interfaces

When bound to multiple interfaces, the `$socket` returned ensures replies come from the correct source IP:

```php
$server = new UdpServer(['192.168.1.10:9000', '10.0.0.10:9000']);

foreach ($server->receive() as $peer => [$data, $socket]) {
    // $socket is the interface that received the packet
    // Reply will have the correct source IP
    stream_socket_sendto($socket, "Reply", 0, $peer);
}
```

### API

```php
// Constructor
new UdpServer(string|array $addresses, array $context = [])

// Receive datagrams (generator)
$server->receive(int $maxLength = 65536): Generator<string, array{string, resource}>
// yields peer => [data, socket]

// Send (for initiating sends, not replies)
$server->send(string $address, string $data, int $flags = 0): int|false

// Management
$server->close(): void
$server->isClosed(): bool
$server->getAddresses(): array
```

## Performance

These implementations leverage phasync coroutines and non-blocking I/O for high performance:

- **Zero-copy buffers**: Read/write buffers are disabled for direct kernel access
- **TCP_NODELAY**: Disabled Nagle algorithm for low-latency responses
- **SO_REUSEPORT**: Enabled by default for graceful restarts and multi-process scaling
- **Large chunk size**: 64KB chunks for efficient data transfer

In synthetic benchmarks, this can handle 50% more requests per second than Node.js.

## Design Guidelines

### Read/Write Strategy

- **Read big**: Always read large chunks (65536 bytes) and buffer yourself if needed
- **Write for your use case**: Large chunks for bandwidth, small chunks for low latency

### Socket Options

| Option | Default | Reason |
|--------|---------|--------|
| `so_reuseport` | `true` | Graceful restarts, multi-process scaling |
| `tcp_nodelay` | `true` | Low-latency delivery of small messages |
| `backlog` | `511` | Handle connection bursts |

Override via the `$context` parameter:

```php
$server = new TcpServer('0.0.0.0:8080', [
    'socket' => [
        'tcp_nodelay' => false,  // Enable Nagle algorithm
        'backlog' => 1024,
    ]
]);
```

## Deprecated: Server Class

The original `Server` class is deprecated. Migrate to `TcpServer` or `UdpServer`:

```php
// Old (deprecated)
Server::serve('tcp://127.0.0.1:8080', function($stream, $peer) {
    // handle connection
});

// New
$server = new TcpServer('127.0.0.1:8080');
foreach ($server->accept() as $peer => $stream) {
    phasync::go(fn() => /* handle connection */);
}
```
