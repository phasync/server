# phasync/server

> **Note:** This package will be renamed to `phasync/net` in a future release.

TCP, UDP and Unix socket networking for PHP using phasync coroutines.

The design follows Go's `net` package: a server listens on one address and hands you
connections from a plain loop, and you start one coroutine per connection.

## Installation

```bash
composer require phasync/server
```

For production, also see [the phasync extension](#the-phasync-extension) and
[JIT](#jit).

## TcpServer

```php
use phasync\Net\TcpServer;

phasync::run(function () {
    $server = new TcpServer('0.0.0.0:8080');

    foreach ($server->accept() as $peer => $stream) {
        phasync::go(function () use ($stream, $peer) {
            $request = fread(phasync::readable($stream), 65536);

            fwrite(phasync::writable($stream),
                "HTTP/1.1 200 OK\r\n" .
                "Connection: close\r\n" .
                "Content-Length: " . strlen("Hello, $peer!") . "\r\n" .
                "\r\n" .
                "Hello, $peer!"
            );
            fclose($stream);
        });
    }
});
```

Accepted connections are plain non-blocking stream resources. Call `phasync::readable()` /
`phasync::writable()` before reading or writing, so the coroutine waits instead of getting
an empty read. With the phasync extension loaded, plain `fread()` / `fwrite()` wait by
themselves.

`accept()` takes every connection already waiting in the kernel's queue before waiting
again, so a burst of new connections is admitted at once. The loop ends when the server is
closed (`$server->close()` from any coroutine).

### Several addresses

A server listens on one address. For several, run one accept loop per address:

```php
foreach (['0.0.0.0:80', '0.0.0.0:8080'] as $address) {
    phasync::go(function () use ($address) {
        $server = new TcpServer($address);
        foreach ($server->accept() as $peer => $stream) {
            phasync::go(fn () => handle($stream, $peer));
        }
    });
}
```

To handle connections from all of them in one place, have each loop write to a shared
channel instead:

```php
phasync::channel($connections, $newConnection);

foreach (['0.0.0.0:80', '0.0.0.0:8080'] as $address) {
    phasync::go(function () use ($address, $newConnection) {
        $server = new TcpServer($address);
        foreach ($server->accept() as $peer => $stream) {
            $newConnection->write([$peer, $stream]);
        }
    });
}

phasync::go(function () use ($connections) {
    foreach ($connections as [$peer, $stream]) {
        phasync::go(fn () => handle($stream, $peer));
    }
});
```

Read the channel from its own coroutine, as above, not from the coroutine that created it:
phasync currently treats a channel's creator waiting on it for more than 100 ms as a likely
deadlock and throws.

### Unix domain sockets

```php
$server = new TcpServer('unix:///run/myapp.sock');
```

`close()` removes the socket file, so the path can be bound again after a restart.

### Port 0

Binding to port 0 picks a free port; `getAddress()` returns the real one:

```php
$server = new TcpServer('127.0.0.1:0');
echo $server->getAddress(); // 127.0.0.1:43127
```

### API

```php
new TcpServer(string $address, array $context = [])

$server->accept(): Generator<string, resource>  // yields peer => stream
$server->close(): void
$server->isClosed(): bool
$server->getAddress(): string
```

## UdpServer

```php
use phasync\Net\UdpServer;

phasync::run(function () {
    $server = new UdpServer('0.0.0.0:9000');

    foreach ($server->receive() as $peer => $data) {
        $server->send($peer, "Echo: $data");
    }
});
```

`receive()` takes every datagram already waiting before waiting again. One server is one
socket; for several addresses, run one receive loop per address as with `TcpServer`.

### API

```php
new UdpServer(string $address, array $context = [])

$server->receive(int $maxLength = 65536): Generator<string, string>  // yields peer => data
$server->send(string $address, string $data, int $flags = 0): int|false
$server->close(): void
$server->isClosed(): bool
$server->getAddress(): string
```

## TcpClient

Connect without blocking the event loop, including DNS resolution:

```php
use phasync\Net\TcpClient;

phasync::run(function () {
    $conn = TcpClient::connect('example.com:80');

    fwrite(phasync::writable($conn), "GET / HTTP/1.0\r\nHost: example.com\r\n\r\n");
    while (!feof($conn)) {
        echo fread(phasync::readable($conn), 65536);
    }
    fclose($conn);
});
```

```php
TcpClient::connect(string $address, float $timeout = 30, array $context = []): resource
TcpClient::connectUnix(string $path, float $timeout = 30, array $context = []): resource
```

## Dns

Async DNS resolution (used automatically by `TcpClient`):

```php
use phasync\Net\Dns;
use phasync\Net\DnsRecordType;

phasync::run(function () {
    // Default: tries A (IPv4) first, then AAAA (IPv6).
    // Returns a random IP when there are several records.
    $ip = Dns::resolve('example.com');

    $allIps = Dns::resolveAll('example.com', DnsRecordType::A);
    $ipv4   = Dns::resolve('example.com', DnsRecordType::A);
    $ipv6   = Dns::resolve('example.com', DnsRecordType::AAAA);
});
```

- Non-blocking UDP queries to the system nameserver
- IPv4 (A) and IPv6 (AAAA)
- Reads `/etc/hosts` first
- Caches results using the DNS TTL
- Falls back to 8.8.8.8 if no nameserver is configured

```php
Dns::resolve(string $hostname, DnsRecordType $type = DnsRecordType::ANY, float $timeout = 2.0): ?string
Dns::resolveAll(string $hostname, DnsRecordType $type = DnsRecordType::ANY, float $timeout = 2.0): array
Dns::clearCache(): void
```

## Socket options

| Option | Default | Why |
|--------|---------|-----|
| `backlog` | `65535` | Room for connection bursts. The kernel caps it at its own limit (`net.core.somaxconn` on Linux), so a high value is safe. |
| `so_reuseport` | `true` | Several processes can listen on the same port, for multi-process scaling and graceful restarts. |
| `tcp_nodelay` | `true` | Small responses are sent immediately instead of waiting to be batched. |

Override them with `$context`:

```php
$server = new TcpServer('0.0.0.0:8080', [
    'socket' => ['tcp_nodelay' => false],
]);
```

## Production

### The phasync extension

phasync waits for sockets with `stream_select()`, which in PHP fails for any file descriptor
numbered 1024 or higher. A server with more than roughly 1,000 open connections hits that.
The [phasync extension](https://github.com/phasync/phasync-ext) replaces it with a version
without that limit, and also makes plain `fread()` / `fwrite()` suspend the coroutine. It is
CLI only.

```bash
composer require phasync/phasync-ext
```

```php
phasync\try_enable_ext(); // first line of your script; may restart the process once
```

### JIT

PHP's JIT is off by default on the command line. For a server, turn it on:

```bash
php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M server.php
```

In the benchmark below it added 8–18% throughput.

## Benchmark

Hello-world keep-alive HTTP responder on raw sockets (no HTTP library), one process each:
`TcpServer` with the phasync extension and JIT, and node v18 with the `net` module.
`wrk -t8 -d10s` on a Ryzen 9 9950X3D:

| Connections | phasync req/s | node req/s | phasync p99 | node p99 |
|---|---|---|---|---|
| 100 | 248k | 266k | 0.51 ms | 0.48 ms |
| 900 | 222k | 250k | 4.9 ms | 381 ms |
| 10,000 | 105k | 154k* | 98 ms | 26 ms* |

\* At 10,000 connections node had only accepted about 7,500 of them after 5 seconds, with
352 timeouts and 52 read errors, so it was serving fewer clients. phasync had all 10,000
connections accepted with no errors.

This handler does almost nothing per request, which favours node: its event loop runs in C
while phasync's runs in PHP. With more work per request, phasync's per-request overhead is
lower: waiting on I/O suspends a fiber, where node allocates promises for every `await`.

The scripts are in [`benchmarking/`](benchmarking/).

## Upgrading from 1.x

2.0 requires phasync 2.0 and changes the API:

- **`phasync\Server\Server` is removed.** Use `TcpServer` or `UdpServer`:

  ```php
  // 1.x
  Server::serve('tcp://127.0.0.1:8080', function ($stream, $peer) {
      // handle connection
  });

  // 2.0
  $server = new TcpServer('127.0.0.1:8080');
  foreach ($server->accept() as $peer => $stream) {
      phasync::go(fn () => /* handle connection */);
  }
  ```

- **One address per server.** `new TcpServer([...])` with several addresses is gone; see
  [Several addresses](#several-addresses). `getAddresses(): array` is now
  `getAddress(): string`.
- **No AsyncStream wrapping.** `TcpServer::accept()` and `TcpClient::connect()` return plain
  non-blocking streams. Wait with `phasync::readable()` / `phasync::writable()` before reading
  or writing, or load the phasync extension. The `$wrapStreams`, `$readBuffer` and
  `$writeBuffer` constructor parameters are removed.
- **`UdpServer::receive()` yields `peer => data`** instead of `peer => [data, socket]`; reply
  with `$server->send($peer, $data)`.
