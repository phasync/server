<?php

namespace phasync\Net;

use phasync;
use function phasync\file_get_contents;

/**
 * Minimal async DNS resolver for phasync.
 *
 * Performs non-blocking DNS lookups using UDP.
 *
 * Example:
 * ```php
 * $ip = Dns::resolve('example.com');
 * $conn = TcpClient::connect("$ip:80");
 * ```
 */
final class Dns
{
    private static array $cache = [];
    private static ?array $hosts = null;

    /**
     * Resolve hostname to IP address.
     *
     * @param string $hostname Hostname to resolve
     * @param DnsRecordType $type Record type (A, AAAA, or ANY)
     * @param float $timeout Timeout in seconds
     * @return string|null IP address or null on failure
     */
    public static function resolve(
        string $hostname,
        DnsRecordType $type = DnsRecordType::ANY,
        float $timeout = 2.0
    ): ?string {
        // Already an IP?
        if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ($type === DnsRecordType::AAAA) ? null : $hostname;
        }
        if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return ($type === DnsRecordType::A) ? null : $hostname;
        }

        // Check /etc/hosts
        $hostsIp = self::checkHosts($hostname, $type);
        if ($hostsIp !== null) {
            return $hostsIp;
        }

        // Check cache
        $cacheKey = $hostname . ':' . $type->value;
        if (isset(self::$cache[$cacheKey])) {
            if (time() < self::$cache[$cacheKey]['expires']) {
                return self::$cache[$cacheKey]['ip'];
            }
            unset(self::$cache[$cacheKey]);
        }

        // For ANY, try A first, then AAAA
        if ($type === DnsRecordType::ANY) {
            $result = self::resolve($hostname, DnsRecordType::A, $timeout);
            if ($result !== null) {
                return $result;
            }
            return self::resolve($hostname, DnsRecordType::AAAA, $timeout);
        }

        // Query nameserver
        $nameserver = self::getSystemNameserver();
        $result = self::query($nameserver, $hostname, $type, $timeout);

        if ($result !== null) {
            self::$cache[$cacheKey] = [
                'ip' => $result['ip'],
                'expires' => time() + $result['ttl'],
            ];
            return $result['ip'];
        }

        return null;
    }

    /**
     * Clear the DNS cache.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function checkHosts(string $hostname, DnsRecordType $type): ?string
    {
        if (self::$hosts === null) {
            self::$hosts = ['v4' => [], 'v6' => []];
            $hostsFile = PHP_OS_FAMILY === 'Windows'
                ? 'C:\\Windows\\System32\\drivers\\etc\\hosts'
                : '/etc/hosts';

            if (file_exists($hostsFile)) {
                $content = file_get_contents($hostsFile);
                if ($content !== false) {
                    foreach (explode("\n", $content) as $line) {
                        $line = trim(preg_replace('/#.*/', '', $line));
                        if ($line === '') continue;

                        $parts = preg_split('/\s+/', $line);
                        if (count($parts) >= 2) {
                            $ip = $parts[0];
                            $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
                            $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

                            if ($isV4 || $isV6) {
                                $key = $isV4 ? 'v4' : 'v6';
                                for ($i = 1; $i < count($parts); $i++) {
                                    self::$hosts[$key][strtolower($parts[$i])] = $ip;
                                }
                            }
                        }
                    }
                }
            }
        }

        $hostLower = strtolower($hostname);

        if ($type === DnsRecordType::A) {
            return self::$hosts['v4'][$hostLower] ?? null;
        }
        if ($type === DnsRecordType::AAAA) {
            return self::$hosts['v6'][$hostLower] ?? null;
        }
        // ANY: prefer v4
        return self::$hosts['v4'][$hostLower] ?? self::$hosts['v6'][$hostLower] ?? null;
    }

    private static function query(string $server, string $hostname, DnsRecordType $type, float $timeout): ?array
    {
        $socket = @stream_socket_client("udp://$server:53", $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT);
        if (!$socket) {
            return null;
        }

        stream_set_blocking($socket, false);

        try {
            // Build DNS query
            $id = random_int(0, 65535);
            $header = pack('n6', $id, 0x0100, 1, 0, 0, 0); // Standard query, recursion desired

            $question = '';
            foreach (explode('.', $hostname) as $label) {
                $question .= chr(strlen($label)) . $label;
            }
            $question .= "\0" . pack('nn', $type->value, 1); // Type, Class IN

            phasync::writable($socket, $timeout);
            stream_socket_sendto($socket, $header . $question);

            phasync::readable($socket, $timeout);
            $response = fread($socket, 512);
        } catch (\Throwable) {
            return null;
        } finally {
            @fclose($socket);
        }

        return self::parseResponse($response, $id, $type);
    }

    private static function parseResponse(string $response, int $expectedId, DnsRecordType $type): ?array
    {
        if (strlen($response) < 12) {
            return null;
        }

        // Validate ID
        $id = unpack('n', substr($response, 0, 2))[1];
        if ($id !== $expectedId) {
            return null;
        }

        // Check response flags
        $flags = unpack('n', substr($response, 2, 2))[1];
        $rcode = $flags & 0x000F;
        if ($rcode !== 0) {
            return null; // NXDOMAIN, SERVFAIL, etc.
        }

        $answerCount = unpack('n', substr($response, 6, 2))[1];
        if ($answerCount === 0) {
            return null;
        }

        // Skip header
        $offset = 12;

        // Skip question section
        self::skipName($response, $offset);
        $offset += 4; // Type + Class

        // Parse answers
        for ($i = 0; $i < $answerCount; $i++) {
            if ($offset >= strlen($response)) {
                break;
            }

            self::skipName($response, $offset);

            if ($offset + 10 > strlen($response)) {
                break;
            }

            $meta = unpack('ntype/nclass/Nttl/nlen', substr($response, $offset, 10));
            $offset += 10;

            // Type A (1) = 4 bytes IPv4
            if ($meta['type'] === 1 && $meta['len'] === 4 && $type === DnsRecordType::A) {
                $ip = inet_ntop(substr($response, $offset, 4));
                $ttl = max(60, min($meta['ttl'], 86400));
                return ['ip' => $ip, 'ttl' => $ttl];
            }

            // Type AAAA (28) = 16 bytes IPv6
            if ($meta['type'] === 28 && $meta['len'] === 16 && $type === DnsRecordType::AAAA) {
                $ip = inet_ntop(substr($response, $offset, 16));
                $ttl = max(60, min($meta['ttl'], 86400));
                return ['ip' => $ip, 'ttl' => $ttl];
            }

            $offset += $meta['len'];
        }

        return null;
    }

    private static function skipName(string $packet, int &$offset): void
    {
        while ($offset < strlen($packet)) {
            $len = ord($packet[$offset]);

            if ($len === 0) {
                $offset++;
                return;
            }

            if (($len & 0xC0) === 0xC0) {
                // Compression pointer
                $offset += 2;
                return;
            }

            $offset += $len + 1;
        }
    }

    private static function getSystemNameserver(): string
    {
        static $nameserver = null;

        if ($nameserver !== null) {
            return $nameserver;
        }

        if (file_exists('/etc/resolv.conf')) {
            $content = file_get_contents('/etc/resolv.conf');
            if ($content !== false) {
                foreach (explode("\n", $content) as $line) {
                    if (preg_match('/^nameserver\s+(\S+)/', $line, $m)) {
                        $nameserver = $m[1];
                        return $nameserver;
                    }
                }
            }
        }

        // Fallback to Google DNS
        $nameserver = '8.8.8.8';
        return $nameserver;
    }
}
