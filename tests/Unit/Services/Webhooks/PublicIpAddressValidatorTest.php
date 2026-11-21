<?php

namespace Tests\Unit\Services\Webhooks;

use App\Services\Webhooks\PublicIpAddressValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicIpAddressValidatorTest extends TestCase
{
    #[DataProvider('publicAddresses')]
    public function test_it_accepts_public_ip_addresses(string $ip): void
    {
        $validator = new PublicIpAddressValidator;

        $this->assertTrue($validator->isPublic($ip));
    }

    public static function publicAddresses(): array
    {
        return [
            'Google DNS IPv4' => ['8.8.8.8'],
            'Cloudflare DNS IPv4' => ['1.1.1.1'],
            'Google DNS IPv6' => ['2001:4860:4860::8888'],
        ];
    }

    #[DataProvider('nonPublicAddresses')]
    public function test_it_rejects_non_public_ip_addresses(string $ip): void
    {
        $validator = new PublicIpAddressValidator;

        $this->assertFalse($validator->isPublic($ip));
    }

    public static function nonPublicAddresses(): array
    {
        return [
            'Loopback' => ['127.0.0.1'],
            'Private class A' => ['10.0.0.1'],
            'Private class B' => ['172.16.0.1'],
            'Private class C' => ['192.168.1.1'],
            'Link local' => ['169.254.169.254'],

            'Carrier grade NAT' => ['100.64.0.1'],
            'Special purpose IPv4' => ['192.0.0.1'],
            'Documentation IPv4' => ['192.0.2.1'],
            'Network testing range' => ['198.18.0.1'],
            'Documentation range 2' => ['198.51.100.1'],
            'Documentation range 3' => ['203.0.113.1'],
            'Multicast IPv4' => ['224.0.0.1'],
            'Reserved IPv4' => ['240.0.0.1'],
            'Unspecified IPv4' => ['0.0.0.0'],

            'IPv6 loopback' => ['::1'],
            'IPv6 unique local' => ['fc00::1'],
            'IPv6 link local' => ['fe80::1'],
            'IPv6 documentation' => ['2001:db8::1'],
            'IPv6 special purpose' => ['2001::1'],
            'IPv6 outside global unicast' => ['4000::1'],
            'IPv4 mapped IPv6' => ['::ffff:8.8.8.8'],

            'Invalid address' => ['not-an-ip'],
        ];
    }
}
