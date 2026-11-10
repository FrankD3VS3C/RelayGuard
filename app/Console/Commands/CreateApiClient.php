<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\ApiClient;

# #[Signature('app:create-api-client')]
# #[Description('Command description')]


class CreateApiClient extends Command
{
    protected $signature = 'relayguard:client:create {name?}';

    protected $description = 'Create a RelayGuard API client';

    public function handle(): int
    {
        $name = trim(
            (string) ($this->argument('name') ?? $this->ask('Client name'))
        );

        if ($name === '' || mb_strlen($name) > 120) {
            $this->error('The client name must contain between 1 and 120 characters.');

            return self::FAILURE;
        }

        $plainTextKey = 'rg_live_' . bin2hex(random_bytes(32));

        $client = ApiClient::create([
            'name' => $name,
            'key_hash' => hash('sha256', $plainTextKey),
            'is_active' => true,
        ]);

        $this->newLine();
        $this->info('API client created successfully.');
        $this->line('Client ID: ' . $client->id);
        $this->line('Client name: ' . $client->name);
        $this->newLine();

        $this->warn('Your API key is displayed only once. Store it securely.');
        $this->line($plainTextKey);

        $this->newLine();

        return self::SUCCESS;
    }
}
