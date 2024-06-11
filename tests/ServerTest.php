<?php

use phasync\CancelledException;
use phasync\Server\Server;
use phasync\TimeoutException;

beforeEach(function () {
    // Setup code before each test if needed
});

afterEach(function () {
    // Cleanup code after each test if needed
});

test('can create a TCP server', function () {
    phasync::run(function() {
        $fiber = Server::serve('tcp://127.0.0.1:8080', function($stream, $peer) {
            phasync::readable($stream);     
            $data = fread($stream, 65536);
            phasync::writable($stream);     
            fwrite($stream, "HTTP/1.1 200 Ok\r\nConnection: close\r\nContent-Length: 13\r\n\r\nHello, $peer!");
            fclose($stream);
        });
    
        expect($fiber)->toBeInstanceOf(Fiber::class);
        phasync::cancel($fiber);    
    });
});

test('can create a UDP server', function () {
    phasync::run(function() {
        echo "UDP TEST\n";
        $fiber = Server::serve('udp://127.0.0.1:9000', function(Server $server) {
            try {
                while (true) {
                    $data = $server->recvfrom(65536, 0, $peer);
                    if ($data !== false) {
                        $server->sendto("Response Data", 0, $peer);
                    }
                }    
            } catch (CancelledException) {}
        });
    
        expect($fiber)->toBeInstanceOf(Fiber::class);
        phasync::cancel($fiber);
    });

});

test('server throws exception for invalid address', function () {
    phasync::run(function() {
        $invalidAddress = 'invalid://127.0.0.1:8080';

        expect(fn() => new Server($invalidAddress))->toThrow(LogicException::class);    
    });
});

test('server can accept TCP connections', function () {
    phasync::run(function() {
        $fiber = Server::serve('tcp://127.0.0.1:8080', function($stream, $peer) {
            phasync::readable($stream);
            $data = fread($stream, 65536);
            fwrite($stream, "Received: $data");
            fclose($stream);
        });

        phasync::go(function() use ($fiber) {
            $client = stream_socket_client('tcp://127.0.0.1:8080');
            fwrite(phasync::writable($client), "Hello Server");
            $response = fread(phasync::readable($client), 65536);
            fclose($client);
        
            expect($response)->toContain("Received: Hello Server");
            phasync::cancel($fiber);        
        });
    });
});

test('server can send and receive UDP messages', function () {
    phasync::run(function() {
        $fiber = Server::serve('udp://127.0.0.1:9000', function(Server $server) {
            try {
                while (true) {
                    $data = $server->recvfrom(65536, 0, $peer);
                    if ($data !== false) {
                        $server->sendto("Received: $data", 0, $peer);
                    }
                }    
            } catch (CancelledException) {}
        });
    
        phasync::go(function() use ($fiber) {
            $client = stream_socket_client('udp://127.0.0.1:9000');
            fwrite(phasync::writable($client), "Hello Server");
            $response = fread(phasync::readable($client), 65536);
            fclose($client);
        
            expect($response)->toContain("Received: Hello Server");
            phasync::cancel($fiber);        
        });
    });
});

test('server can handle multiple concurrent TCP connections', function () {
    phasync::run(function() {
        $fiber = Server::serve('tcp://127.0.0.1:8080', function($stream, $peer) {
            phasync::readable($stream);
            $data = fread($stream, 65536);
            phasync::writable($stream);
            fwrite($stream, "Received: $data");
            fclose($stream);
        });

        phasync::go(function() use ($fiber) {
            $clients = [];
            $responses = [];
            for ($i = 0; $i < 5; $i++) {
                $clients[$i] = stream_socket_client('tcp://127.0.0.1:8080');
                fwrite(phasync::writable($clients[$i]), "Hello Server $i");
            }
            for ($i = 0; $i < 5; $i++) {
                $responses[$i] = fread(phasync::readable($clients[$i]), 65536);
                fclose($clients[$i]);
            }
        
            foreach ($responses as $i => $response) {
                expect($response)->toContain("Received: Hello Server $i");
            }
        
            phasync::cancel($fiber);    
        });
    
    });
});

test('server handles connection timeout', function () {
    phasync::run(function() {
        $fiber = Server::serve('tcp://127.0.0.1:8080', function($stream, $peer) {
            phasync::readable($stream, 0.1);  // Short timeout
            $data = fread($stream, 65536);
            fwrite($stream, "Received: $data");
            fclose($stream);
        }, 0.1);
    
        phasync::go(function() use ($fiber) {
            $client = stream_socket_client('tcp://127.0.0.1:8080', $errno, $errstr, 0.2);
            phasync::sleep(1);  // Delay to trigger timeout
            fwrite(phasync::writable($client), "Hello Server");
            $response = fread(phasync::readable($client), 65536);
            fclose($client);
        
            expect($response)->toBeEmpty();
        });

        expect(fn() => phasync::await($fiber))->toThrow(TimeoutException::class);
    });
});
