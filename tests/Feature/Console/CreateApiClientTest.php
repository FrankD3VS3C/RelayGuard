<?php

namespace Tests\Feature\Console;

use App\Models\ApiClient;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CreateApiClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_api_client_with_a_secure_key(): void
    {
        $exitCode = Artisan::call(
            'relayguard:client:create',
            ['name' => 'Test client']
        );

        $output = Artisan::output();

        $client = ApiClient::query()->firstOrFail();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(1, ApiClient::count());

        $this->assertSame('Test client', $client->name);
        $this->assertTrue($client->is_active);

        preg_match(
            '/rg_live_[a-f0-9]{64}/',
            $output,
            $matches
        );

        $this->assertNotEmpty($matches);

        $plainTextKey = $matches[0];
        $storedHash = $client->getRawOriginal('key_hash');

        $this->assertSame(
            hash('sha256', $plainTextKey),
            $storedHash
        );

        $this->assertNotSame($plainTextKey, $storedHash);

        $this->assertSame(
            1,
            substr_count($output, $plainTextKey)
        );
    }

    public function test_it_rejects_an_empty_client_name(): void
    {
        $exitCode = Artisan::call(
            'relayguard:client:create',
            ['name' => '']
        );

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame(0, ApiClient::count());
    }

    public function test_it_rejects_a_client_name_longer_than_120_characters(): void
    {
        $exitCode = Artisan::call(
            'relayguard:client:create',
            ['name' => str_repeat('a', 121)]
        );

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame(0, ApiClient::count());
    }
}
