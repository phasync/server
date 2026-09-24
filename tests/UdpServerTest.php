<?php

use phasync\Net\UdpServer;

test('UdpServer binds to port 0 and reports the real address', function () {
    $server = new UdpServer('127.0.0.1:0');
    expect($server->getAddress())->toMatch('/^127\.0\.0\.1:[1-9][0-9]*$/');
    $server->close();
});

test('UdpServer yields peer => data and can reply with send()', function () {
    $response = phasync::run(function () {
        $server = new UdpServer('127.0.0.1:0');

        phasync::go(function () use ($server) {
            foreach ($server->receive() as $peer => $data) {
                $server->send($peer, "Echo: $data");
                $server->close();
            }
        });

        $client = stream_socket_client('udp://' . $server->getAddress());
        stream_set_blocking($client, false);
        fwrite($client, 'Hello UDP');
        $response = fread(phasync::readable($client), 65536);
        fclose($client);

        return $response;
    });

    expect($response)->toBe('Echo: Hello UDP');
});

test('UdpServer takes datagrams already waiting without suspending', function () {
    $result = phasync::run(function () {
        $server = new UdpServer('127.0.0.1:0');
        $client = stream_socket_client('udp://' . $server->getAddress());
        for ($i = 0; $i < 20; ++$i) {
            fwrite($client, "msg$i");
        }
        phasync::sleep(0.05);

        $turns   = 0;
        $stop    = false;
        $sibling = phasync::go(function () use (&$turns, &$stop) {
            while (!$stop) {
                ++$turns;
                phasync::sleep(0);
            }
        });

        $before   = $turns;
        $received = [];
        foreach ($server->receive() as $data) {
            $received[] = $data;
            if (20 === count($received)) {
                break;
            }
        }
        $siblingTurns = $turns - $before;

        $stop = true;
        phasync::await($sibling);
        fclose($client);
        $server->close();

        return [count($received), $received[0], $received[19], $siblingTurns];
    });

    expect($result)->toBe([20, 'msg0', 'msg19', 0]);
});

test('closing the server ends a waiting receive() loop without an exception', function () {
    $result = phasync::run(function () {
        $server = new UdpServer('127.0.0.1:0');
        $loop   = phasync::go(function () use ($server) {
            foreach ($server->receive() as $data) {
            }

            return 'loop ended';
        });
        phasync::sleep(0.05);
        $server->close();

        return [phasync::await($loop), $server->isClosed()];
    });

    expect($result)->toBe(['loop ended', true]);
});

test('UdpServer throws on an address it cannot bind', function () {
    expect(fn () => new UdpServer('invalid:99999'))->toThrow(RuntimeException::class);
});
