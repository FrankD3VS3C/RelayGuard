<?php

namespace App\Services\Webhooks;

final readonly class ValidatedWebhookDestination
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public string $scheme,
        public string $hostname,
        public int $port,
        public array $addresses,
    ) {}

    public function selectedAddress(): string
    {
        return $this->addresses[0];
    }
}
