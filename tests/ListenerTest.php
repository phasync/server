<?php

use phasync\Util\WaitGroup;

test('Listener binds to port 0 and reports the real address', function () {
    $server = phasync\Net\listen('127.0.0.1:0');
    expect($server->addr())->toMatch('/^127\.0\.0\.1:[1-9][0-9]*$/');
    $server->close();
});

test('Listener accepts a connection with foreach and yields peer => stream', function () {
    $result = phasync::run(function () {
        $server = phasync\Net\listen('127.0.0.1:0');
        $got    = null;

        phasync::go(function () use ($server, &$got) {
            foreach ($server as $peer => $stream) {
                $got  = [$peer, fread(phasync::readable($stream), 65536)];
                fwrite(phasync::writable($stream), 'Got: ' . $got[1]);
                fclose($stream);
                $server->close();
            }
        });

        $client = stream_socket_client('tcp://' . $server->addr());
        stream_set_blocking($client, false);
        fwrite(phasync::writable($client), 'Hello');
        $response = fread(phasync::readable($client), 65536);
        fclose($client);

        return [$got, $response];
    });

    [[$peer, $data], $response] = $result;
    expect($peer)->toMatch('/^127\.0\.0\.1:[0-9]+$/');
    expect($data)->toBe('Hello');
    expect($response)->toBe('Got: Hello');
});

test('Listener yields plain stream resources, not AsyncStream-wrapped ones', function () {
    $meta = phasync::run(function () {
        $server = phasync\Net\listen('127.0.0.1:0');
        $client = stream_socket_client('tcp://' . $server->addr());
        foreach ($server as $stream) {
            $meta = stream_get_meta_data($stream);
            fclose($stream);
            break;
        }
        fclose($client);
        $server->close();

        return $meta;
    });

    expect($meta['wrapper_type'] ?? null)->not->toBe('user-space');
    expect($meta['blocked'])->toBeFalse();
});

test('Listener takes connections already waiting in the queue without suspending', function () {
    // With N connections queued, accepting all of them must not wait for the event loop
    // between connections: a sibling coroutine counting its turns must get none.
    $result = phasync::run(function () {
        $server  = phasync\Net\listen('127.0.0.1:0');
        $clients = [];
        for ($i = 0; $i < 20; ++$i) {
            $clients[] = stream_socket_client('tcp://' . $server->addr());
        }
        phasync::sleep(0.05); // let the kernel finish the handshakes into the accept queue

        $turns   = 0;
        $stop    = false;
        $sibling = phasync::go(function () use (&$turns, &$stop) {
            while (!$stop) {
                ++$turns;
                phasync::sleep(0);
            }
        });

        $before   = $turns;
        $accepted = [];
        foreach ($server as $stream) {
            $accepted[] = $stream;
            if (20 === count($accepted)) {
                break;
            }
        }
        $siblingTurns = $turns - $before;

        $stop = true;
        phasync::await($sibling);
        foreach (array_merge($clients, $accepted) as $s) {
            fclose($s);
        }
        $server->close();

        return [count($accepted), $siblingTurns];
    });

    expect($result)->toBe([20, 0]);
});

test('closing the listener ends a waiting foreach loop without an exception', function () {
    $result = phasync::run(function () {
        $server = phasync\Net\listen('127.0.0.1:0');
        $loop   = phasync::go(function () use ($server) {
            foreach ($server as $stream) {
                fclose($stream);
            }

            return 'loop ended';
        });
        phasync::sleep(0.05); // the loop is now waiting for a connection
        $server->close();

        return phasync::await($loop);
    });

    expect($result)->toBe('loop ended');
});

test('Listener throws on an address it cannot bind', function () {
    expect(fn () => phasync\Net\listen('invalid:99999'))->toThrow(RuntimeException::class);
});

test('Listener handles concurrent connections', function () {
    phasync::run(function () {
        $server = phasync\Net\listen('127.0.0.1:0');
        $wg     = new WaitGroup();
        $count  = 10;

        // Echo with a delay, to prove the connections are handled concurrently.
        phasync::go(function () use ($server, $count) {
            $handled = 0;
            foreach ($server as $conn) {
                phasync::go(function () use ($conn) {
                    phasync::sleep(0.05);
                    fwrite(phasync::writable($conn), 'Done');
                    fclose($conn);
                });
                if (++$handled >= $count) {
                    $server->close();
                }
            }
        });

        $start = microtime(true);
        for ($i = 0; $i < $count; ++$i) {
            $wg->add();
            phasync::go(function () use ($server, $wg) {
                try {
                    $client = stream_socket_client('tcp://' . $server->addr());
                    stream_set_blocking($client, false);
                    expect(fread(phasync::readable($client), 1024))->toBe('Done');
                    fclose($client);
                } finally {
                    $wg->done();
                }
            });
        }
        $wg->await();

        // Sequential would take 10 * 0.05 s = 0.5 s.
        expect(microtime(true) - $start)->toBeLessThan(0.3);
    });
});

test('Listener handles large payloads', function () {
    phasync::run(function () {
        $server = phasync\Net\listen('127.0.0.1:0');

        // Read 1 MB, reply with its hash.
        phasync::go(function () use ($server) {
            foreach ($server as $conn) {
                $buffer = '';
                while (true) {
                    $chunk = fread(phasync::readable($conn), 65536);
                    if ('' === $chunk || false === $chunk) {
                        if (feof($conn)) {
                            break;
                        }
                        continue;
                    }
                    $buffer .= $chunk;
                }
                fwrite(phasync::writable($conn), md5($buffer));
                fclose($conn);
                $server->close();
            }
        });

        $client = stream_socket_client('tcp://' . $server->addr());
        stream_set_blocking($client, false);
        $payload = str_repeat('X', 1024 * 1024);
        $offset  = 0;
        while ($offset < strlen($payload)) {
            $written = fwrite(phasync::writable($client), substr($payload, $offset, 65536));
            if (false === $written) {
                break;
            }
            $offset += $written;
        }
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        $response = fread(phasync::readable($client), 1024);
        fclose($client);

        expect($response)->toBe(md5($payload));
    });
});

test('accept() returns one connection and its peer address at a time, like Go', function () {
    $result = phasync::run(function () {
        $listener = phasync\Net\listen('127.0.0.1:0');
        $client   = stream_socket_client('tcp://' . $listener->addr());
        [$conn, $peer] = $listener->accept();
        fwrite($client, 'ping');
        $data = fread(phasync::readable($conn), 10);
        fclose($conn);
        fclose($client);
        $listener->close();

        return [is_resource($conn) || 'resource (closed)' === get_debug_type($conn), $peer, $data];
    });

    expect($result[0])->toBeTrue();
    expect($result[1])->toMatch('/^127\.0\.0\.1:[0-9]+$/');
    expect($result[2])->toBe('ping');
});

test('accept() throws IOException on a closed listener, also in a coroutine already waiting in it', function () {
    $result = phasync::run(function () {
        $listener = phasync\Net\listen('127.0.0.1:0');
        $waiting  = phasync::go(function () use ($listener) {
            try {
                $listener->accept();

                return 'accepted';
            } catch (phasync\IOException $e) {
                return $e->getMessage();
            }
        });
        phasync::sleep(0.05);
        $listener->close();
        $afterwards = null;
        try {
            $listener->accept();
        } catch (phasync\IOException $e) {
            $afterwards = $e->getMessage();
        }

        return [phasync::await($waiting), $afterwards];
    });

    expect($result)->toBe(['The listener is closed', 'The listener is closed']);
});
