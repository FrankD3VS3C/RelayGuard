<?php

namespace App\Services\Webhooks;

use Closure;
use InvalidArgumentException;

class WebhookUrlValidator
{
    public function __construct(
        private readonly PublicIpAddressValidator $ipValidator,
        private readonly ?Closure $dnsResolver = null,
    ) {}

    public function validate(string $url): void
    {
        $this->validateAndResolve($url);
    }

    public function validateAndResolve(string $url): ValidatedWebhookDestination
    {
        if ($url === '' || strlen($url) > 2048) {
            throw new InvalidArgumentException('Invalid webhook URL length.');
        }

        $parts = parse_url($url);

        if ($parts === false) {
            throw new InvalidArgumentException('Malformed webhook URL.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only HTTP and HTTPS URLs are allowed.');
        }

        if ($host === '') {
            throw new InvalidArgumentException('Webhook URL must contain a hostname.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('URL credentials are not allowed.');
        }

        $expectedPort = $scheme === 'https' ? 443 : 80;
        $port = $parts['port'] ?? $expectedPort;

        if ($port !== $expectedPort) {
            throw new InvalidArgumentException('Webhook URL uses a disallowed port.');
        }

        $host = trim($host, '[]');
        $normalizedHost = rtrim($host, '.');

        if (
            $normalizedHost === ''
            || $normalizedHost === 'localhost'
            || str_ends_with($normalizedHost, '.localhost')
            || str_ends_with($normalizedHost, '.local')
            || str_ends_with($normalizedHost, '.internal')
            || str_ends_with($normalizedHost, '.test')
            || str_ends_with($normalizedHost, '.invalid')
            || str_ends_with($normalizedHost, '.example')
        ) {
            throw new InvalidArgumentException('Local or reserved hostnames are not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            if (
                filter_var(
                    $normalizedHost,
                    FILTER_VALIDATE_DOMAIN,
                    FILTER_FLAG_HOSTNAME,
                ) === false
            ) {
                throw new InvalidArgumentException('Invalid webhook hostname.');
            }

            $addresses = $this->resolveHost($normalizedHost);
        }

        if ($addresses === []) {
            throw new InvalidArgumentException('Webhook hostname did not resolve.');
        }

        foreach ($addresses as $address) {
            if (! $this->ipValidator->isPublic($address)) {
                throw new InvalidArgumentException(
                    'Webhook hostname resolves to a non-public IP address.'
                );
            }
        }

        return new ValidatedWebhookDestination(
            scheme: $scheme,
            hostname: $host,
            port: $port,
            addresses: array_values(array_unique($addresses)),
        );
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if ($this->dnsResolver !== null) {
            return ($this->dnsResolver)($host);
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = $record['ip'];
            }

            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
