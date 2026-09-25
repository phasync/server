<?php

beforeEach(function () {
    if ('WIN' === strtoupper(substr(PHP_OS, 0, 3))) {
        $this->markTestSkipped('Unix sockets not supported on Windows');
    }
});

test('Listener supports Unix domain sockets', function () {
    $socketPath = sys_get_temp_dir() . '/phasync_test_' . uniqid() . '.sock';

    $response = phasync::run(function () use ($socketPath) {
        $server = phasync\Net\listen("unix://$socketPath");

        phasync::go(function () use ($server) {
            foreach ($server as $conn) {
                fwrite(phasync::writable($conn), 'Hello Unix');
                fclose($conn);
                $server->close();
            }
        });

        $client = stream_socket_client("unix://$socketPath");
        stream_set_blocking($client, false);
        $response = fread(phasync::readable($client), 65536);
        fclose($client);

        return $response;
    });

    expect($response)->toBe('Hello Unix');
});

test('closing a Unix socket server removes the socket file, so the path can be bound again', function () {
    $socketPath = sys_get_temp_dir() . '/phasync_test_' . uniqid() . '.sock';

    $server = phasync\Net\listen("unix://$socketPath");
    expect(file_exists($socketPath))->toBeTrue();
    $server->close();
    expect(file_exists($socketPath))->toBeFalse();

    $again = phasync\Net\listen("unix://$socketPath");
    $again->close();
    expect(file_exists($socketPath))->toBeFalse();
});
