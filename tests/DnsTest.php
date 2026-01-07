<?php

use phasync\Net\Dns;

test('Dns resolves localhost from hosts file', function () {
    $ip = Dns::resolve('localhost');
    expect($ip)->toBe('127.0.0.1');
});

test('Dns passes through IP addresses', function () {
    $ip = Dns::resolve('192.168.1.1');
    expect($ip)->toBe('192.168.1.1');
});

test('Dns resolves real hostname', function () {
    phasync::run(function () {
        $ip = Dns::resolve('google.com', timeout: 5.0);
        expect($ip)->not->toBeNull();
        expect(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))->not->toBeFalse();
    });
});

test('Dns caches results', function () {
    phasync::run(function () {
        Dns::clearCache();

        $start = microtime(true);
        $ip1 = Dns::resolve('google.com', timeout: 5.0);
        $first = microtime(true) - $start;

        $start = microtime(true);
        $ip2 = Dns::resolve('google.com', timeout: 5.0);
        $second = microtime(true) - $start;

        expect($ip1)->toBe($ip2);
        expect($second)->toBeLessThan($first); // Cache should be faster
        expect($second)->toBeLessThan(0.001); // Should be nearly instant
    });
});

test('Dns returns null for invalid hostname', function () {
    phasync::run(function () {
        $ip = Dns::resolve('this-hostname-definitely-does-not-exist-12345.invalid', timeout: 2.0);
        expect($ip)->toBeNull();
    });
});
