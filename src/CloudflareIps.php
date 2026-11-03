<?php

namespace Pantheon\TerminusGCDN;

/**
 * Class CloudflareIps.
 *
 * Cloudflare's published proxy ranges (https://www.cloudflare.com/ips/),
 * so a proxied domain's DNS answer can be labeled as Cloudflare's own
 * addresses rather than the records in the customer's account. A local
 * check only: nothing here calls Cloudflare.
 *
 * @package Pantheon\TerminusGCDN
 */
final class CloudflareIps
{
    public const RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** Whether $ip is in one of Cloudflare's proxy ranges. False for anything that isn't an IP. */
    public static function contains(string $ip): bool
    {
        $packed = @inet_pton(trim($ip));
        if ($packed === false) {
            return false;
        }
        foreach (self::RANGES as $range) {
            [$network, $bits] = explode('/', $range);
            $net = inet_pton($network);
            if (strlen($net) !== strlen($packed)) {
                continue;
            }
            $bytes = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;
            if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /** Whether every value is a Cloudflare proxy address (false for an empty list). */
    public static function all(array $values): bool
    {
        return $values !== [] && count(array_filter($values, [self::class, 'contains'])) === count($values);
    }
}
