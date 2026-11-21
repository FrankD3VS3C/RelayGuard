<?php

namespace App\Services\Webhooks;

class PublicIpAddressValidator
{
    /**
     * Determine whether an IP address is globally routable.
     */
    public function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        $isIpv4 = strlen($packed) === 4;

        // Reject IPv4-mapped IPv6 addresses.
        if (
            strlen($packed) === 16
            && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff"
        ) {
            return false;
        }

        if ($isIpv4) {
            $blockedRanges = [
                '0.0.0.0/8',
                '10.0.0.0/8',
                '100.64.0.0/10',
                '127.0.0.0/8',
                '169.254.0.0/16',
                '172.16.0.0/12',
                '192.0.0.0/24',
                '192.0.2.0/24',
                '192.88.99.0/24',
                '192.168.0.0/16',
                '198.18.0.0/15',
                '198.51.100.0/24',
                '203.0.113.0/24',
                '224.0.0.0/4',
                '240.0.0.0/4',
            ];
        } else {
            // Only accept IPv6 global unicast space.
            if (! $this->isInCidr($packed, '2000::/3')) {
                return false;
            }

            $blockedRanges = [
                '2001::/23',
                '2001:db8::/32',
                '2002::/16',
            ];
        }

        foreach ($blockedRanges as $range) {
            if ($this->isInCidr($packed, $range)) {
                return false;
            }
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * Check whether a packed IP address belongs to a CIDR range.
     */
    private function isInCidr(string $packedIp, string $cidr): bool
    {
        [$network, $prefix] = explode('/', $cidr, 2);

        $packedNetwork = inet_pton($network);

        if (
            $packedNetwork === false
            || strlen($packedIp) !== strlen($packedNetwork)
        ) {
            return false;
        }

        $prefix = (int) $prefix;

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if (
            substr($packedIp, 0, $fullBytes)
            !== substr($packedNetwork, 0, $fullBytes)
        ) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return
            (ord($packedIp[$fullBytes]) & $mask)
            === (ord($packedNetwork[$fullBytes]) & $mask);
    }
}
