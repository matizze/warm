<?php

namespace App\Support\Media;

/**
 * SSRF guard: an address is blocked when it is loopback, private, link-local,
 * cloud metadata or otherwise reserved (RFC1918 and the IPv6 equivalents).
 */
final class BlockedAddress
{
    /**
     * @var list<string>
     */
    private const BLOCKED_IPV4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /**
     * @var list<string>
     */
    private const BLOCKED_IPV6 = [
        '::/128',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
        '2001:db8::/32',
    ];

    public static function isBlocked(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 16) {
            // IPv4-mapped IPv6 (::ffff:192.168.0.1) is judged as IPv4.
            if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
                $mapped = inet_ntop(substr($packed, 12));

                return $mapped === false || self::isBlocked($mapped);
            }

            foreach (self::BLOCKED_IPV6 as $cidr) {
                if (self::inCidr($packed, $cidr)) {
                    return true;
                }
            }

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        foreach (self::BLOCKED_IPV4 as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return true;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private static function inCidr(string $packedIp, string $cidr): bool
    {
        [$network, $prefix] = explode('/', $cidr, 2);
        $packedNetwork = @inet_pton($network);

        if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedIp)) {
            return false;
        }

        $bits = (int) $prefix;
        $bytes = intdiv($bits, 8);
        $remaining = $bits % 8;

        if ($bytes > 0 && substr($packedIp, 0, $bytes) !== substr($packedNetwork, 0, $bytes)) {
            return false;
        }

        if ($remaining === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remaining) & 0xFF;

        return (ord($packedIp[$bytes]) & $mask) === (ord($packedNetwork[$bytes]) & $mask);
    }
}
