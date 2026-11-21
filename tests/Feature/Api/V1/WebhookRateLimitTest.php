<?php

namespace Tests\Feature\Api\V1;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class WebhookRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'cache.limiter' => 'array',
        ]);
    }

    public function test_it_returns_429_after_60_requests_from_the_same_client(): void
    {
        [$client, $plainTextKey] = $this->createApiClient();

        $limiterKey = 'webhook-ingest:client:'.$client->id;

        RateLimiter::clear($limiterKey);

        for ($i = 0; $i < 60; $i++) {
            RateLimiter::hit($limiterKey, 60);
        }

        $this->withToken($plainTextKey)
            ->postJson('/api/v1/events', [
                'event_type' => 'test.created',
                'payload' => ['test' => true],
            ])
            ->assertStatus(429);
    }

    public function test_different_api_clients_have_independent_rate_limits(): void
    {
        [$firstClient, $firstKey] = $this->createApiClient();
        [$secondClient, $secondKey] = $this->createApiClient();

        $firstLimiterKey = 'webhook-ingest:client:'.$firstClient->id;
        $secondLimiterKey = 'webhook-ingest:client:'.$secondClient->id;

        RateLimiter::clear($firstLimiterKey);
        RateLimiter::clear($secondLimiterKey);

        for ($i = 0; $i < 60; $i++) {
            RateLimiter::hit($firstLimiterKey, 60);
        }

        $this->withToken($secondKey)
            ->withHeaders([
                'Idempotency-Key' => 'independent-client-test',
            ])
            ->postJson('/api/v1/events', [
                'event_type' => 'test.created',
                'payload' => ['test' => true],
            ])
            ->assertStatus(202);

        $this->assertSame(
            60,
            RateLimiter::attempts($firstLimiterKey)
        );

        $this->assertSame(
            1,
            RateLimiter::attempts($secondLimiterKey)
        );
    }

    private function createApiClient(): array
    {
        $plainTextKey = 'rg_live_'.bin2hex(random_bytes(32));

        $client = ApiClient::create([
            'name' => 'Rate limit test client',
            'key_hash' => hash('sha256', $plainTextKey),
            'is_active' => true,
        ]);

        return [$client, $plainTextKey];
    }
}
