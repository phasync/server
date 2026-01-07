<?php

use phasync\Net\Dns;
use phasync\Net\DnsRecordType;

test('Dns resolves localhost from hosts file', function () {
    Dns::clearCache();
    $ip = Dns::resolve('localhost');
    expect($ip)->toBe('127.0.0.1');
});

test('Dns resolves localhost IPv6 from hosts file', function () {
    Dns::clearCache();
    $ip = Dns::resolve('localhost', DnsRecordType::AAAA);
    expect($ip)->toBe('::1');
});

test('Dns passes through IPv4 addresses', function () {
    $ip = Dns::resolve('192.168.1.1');
    expect($ip)->toBe('192.168.1.1');
});

test('Dns passes through IPv6 addresses', function () {
    $ip = Dns::resolve('::1');
    expect($ip)->toBe('::1');
});

test('Dns returns null for IPv4 when requesting AAAA', function () {
    $ip = Dns::resolve('192.168.1.1', DnsRecordType::AAAA);
    expect($ip)->toBeNull();
});

test('Dns returns null for IPv6 when requesting A', function () {
    $ip = Dns::resolve('::1', DnsRecordType::A);
    expect($ip)->toBeNull();
});

test('Dns resolves real hostname', function () {
    phasync::run(function () {
        Dns::clearCache();
        $ip = Dns::resolve('google.com', DnsRecordType::A, 5.0);
        expect($ip)->not->toBeNull();
        expect(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))->not->toBeFalse();
    });
});

test('Dns resolves real hostname AAAA', function () {
    phasync::run(function () {
        Dns::clearCache();
        $ip = Dns::resolve('google.com', DnsRecordType::AAAA, 5.0);
        // May be null if no AAAA record, but if present must be valid IPv6
        if ($ip !== null) {
            expect(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))->not->toBeFalse();
        }
    });
});

test('Dns caches results', function () {
    phasync::run(function () {
        Dns::clearCache();

        $start = microtime(true);
        $ip1 = Dns::resolve('google.com', DnsRecordType::A, 5.0);
        $first = microtime(true) - $start;

        $start = microtime(true);
        $ip2 = Dns::resolve('google.com', DnsRecordType::A, 5.0);
        $second = microtime(true) - $start;

        expect($ip1)->toBe($ip2);
        expect($second)->toBeLessThan($first); // Cache should be faster
        expect($second)->toBeLessThan(0.001); // Should be nearly instant
    });
});

test('Dns returns null for invalid hostname', function () {
    phasync::run(function () {
        $ip = Dns::resolve('this-hostname-definitely-does-not-exist-12345.invalid', DnsRecordType::A, 2.0);
        expect($ip)->toBeNull();
    });
});
