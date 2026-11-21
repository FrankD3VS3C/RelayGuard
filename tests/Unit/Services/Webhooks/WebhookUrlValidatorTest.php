<?php

namespace Tests\Unit\Services\Webhooks;

use App\Services\Webhooks\PublicIpAddressValidator;
use App\Services\Webhooks\WebhookUrlValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class WebhookUrlValidatorTest extends TestCase
{
    private function makeValidator(?\Closure $resolver = null): WebhookUrlValidator
    {
        return new WebhookUrlValidator(
            new PublicIpAddressValidator,
            $resolver,
        );
    }

    public function test_it_accepts_a_public_ip_literal(): void
    {
        $validator = $this->makeValidator();

        $validator->validate('https://8.8.8.8/webhook');

        $this->assertTrue(true);
    }

    public function test_it_accepts_a_public_hostname_with_public_dns_answers(): void
    {
        $validator = $this->makeValidator(
            fn (string $host): array => ['93.184.216.34']
        );

        $validator->validate('https://example.com/webhook');

        $this->assertTrue(true);
    }

    public function test_it_rejects_a_hostname_resolving_to_a_private_ip(): void
    {
        $validator = $this->makeValidator(
            fn (string $host): array => ['93.184.216.34', '127.0.0.1']
        );

        $this->expectException(InvalidArgumentException::class);

        $validator->validate('https://example.com/webhook');
    }

    public function test_it_rejects_localhost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeValidator()->validate('http://localhost/webhook');
    }

    public function test_it_rejects_private_ip_literals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeValidator()->validate('http://192.168.1.1/webhook');
    }

    public function test_it_rejects_url_credentials(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeValidator()->validate('https://user:password@example.com/webhook');
    }

    public function test_it_rejects_disallowed_ports(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeValidator()->validate('https://example.com:8080/webhook');
    }

    public function test_it_rejects_non_http_schemes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeValidator()->validate('file:///etc/passwd');
    }

    public function test_it_rejects_hostnames_without_dns_answers(): void
    {
        $validator = $this->makeValidator(
            fn (string $host): array => []
        );

        $this->expectException(InvalidArgumentException::class);

        $validator->validate('https://example.com/webhook');
    }
}
