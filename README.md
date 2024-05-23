# phasync/server

This library makes it very easy to create an efficient TCP or UDP server in
PHP. It wraps the \stream_socket_server() with sensible configuration details
and enables you to handle multiple concurrent connections very easily.

Example HTTP server:

```php
// Starts listening to port 8080 on localhost
Server::serve('tcp://127.0.0.1:8080', function($stream, $peer) {
    phasync::readable($stream);     // Wait for data from the client
    fread($stream, 65536);
    phasync::writable($stream);     // Wait until the client is ready to receive data
    fwrite($stream, 
        "HTTP/1.1 200 Ok\r\n".
        "Connection: close\r\n".
        "Content-Length: 13\r\n".
        "\r\n".
        "Hello, world!"
    );
    fclose($stream);
});
```

## Performance

This implementation performs very well, and is in many cases faster than node.js at handling concurrent connections (up to 50% more requests per second handled in synthetic benchmarks). This performance is due to leveraging phasync coroutines  and non-blocking IO (the `phasync::readable()` and `phasync::writable()` calls in the example above), along with PHP 8.3 JIT.

## Class Overview

The `Server` class provides a simplified interface for creating efficient network servers. It handles the complexities of socket creation, configuration, and connection management, allowing you to focus on your application logic.

## Class Methods

`public static function serve(string $address, ?Closure $socketHandler = null): Fiber`

Creates and starts a TCP server. If the protocol is UDP, it returns a Server instance - otherwise
it returns null when the server is closed (by throwing CancelledException from a socket handler).

Parameters:

 * `$address` (string): The address to bind to, in the format protocol://host:port (e.g., tcp://127.0.0.1:8080 or udp://0.0.0.0:9000).
 * `$handler` (Closure): For TCP connection, the closure will be run inside a coroutine receiving the stream resource and the peer address as arguments. For UDP connections, the closure will run inside a coroutine receiving the Server instance as argument.

 Returns:

  * `void`

## TCP Example

```php
Server::serve('tcp://127.0.0.1:8080', function($stream, $peer) {
    // ... handle TCP connection
});
```

## UDP Example:

```php
Server::serve('udp://127.0.0.1:9000', function(Server $server) {
    while (true) {
        $data = $server->recvfrom(65536, 0, $peer);
        if ($data !== false) {
            $server->sendto("Response Data", 0, $peer);
        }
    }
});

# Design Decisions

## Disabled read and write buffering and large chunk size

Instead of having PHP manage buffering of reads and writes, developers should try to
read and write big chunks of data. Don't try to read 10 bytes, read 65536 bytes at
a time and do the buffering yourself if needed.

When you read big chunks, the benefit of buffering is gone and the end result is
slightly faster. Also the socket works more like a developer would expect.

In short:

 * Write big chunks for bandwidth
 * Write short chunks for low latency
 * Always read big chunks and process or buffer the data yourself

## so_reuseport

This is enabled by default. The assumption is that we want to avoid having the network
port be busy for a while after the application terminates, for example due to an error.
Also, this enables other processes owned by the same user to start listening to the
same port, simplifying scaling up the application to multiple processes.

### TCP Nagle algorithm

By default the Nagle algorithm is disabled. This decision is based on the assumption
that if a developer writes a short message to a socket, the developer wants that message
delivered quickly. For messages that are longer than a network packet, Nagle has little
effect. So generally, we are proponents of letting the developer build larger chunks before
writing if the developer intends to for example send a full HTML response. See also
the chapter above about disabled read/write buffering.

To enable the Nagle algorithm you must pass a custom stream context to the phasync/Server
constructor.
