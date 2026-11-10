<?php

namespace Tests\Feature\Api\V1;

use App\Models\ApiClient;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\WebhookEndpoint;

class StoreWebhookEventTest extends TestCase
{
    use RefreshDatabase;

    private function createApiClient(bool $active = true): array
    {
        $plainTextKey = 'rg_test_' . bin2hex(random_bytes(32));

        $client = ApiClient::create([
            'name' => 'Automated Test Client',
            'key_hash' => hash('sha256', $plainTextKey),
            'is_active' => $active,
        ]);

        return [$client, $plainTextKey];
    }

    private function createEndpoint(
        ApiClient $client,
        bool $active = true
    ): WebhookEndpoint {
        return WebhookEndpoint::create([
            'api_client_id' => $client->id,
            'name' => 'Test Endpoint',
            'url' => 'https://example.com/webhooks/test',
            'signing_secret' => bin2hex(random_bytes(32)),
            'is_active' => $active,
        ]);
    }

    private function validPayload(): array
    {
        return [
            'event_type' => 'payment.created',
            'payload' => [
                'payment_id' => 'pay_test_001',
                'amount' => 99.90,
                'currency' => 'EUR',
            ],
        ];
    }

    public function test_request_without_api_key_is_rejected(): void
    {
        $response = $this->postJson(
            '/api/v1/events',
            $this->validPayload(),
            ['Idempotency-Key' => 'test-001']
        );

        $response->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_valid_event_is_accepted_and_stored(): void
    {
        [$client, $apiKey] = $this->createApiClient();

        $response = $this->withToken($apiKey)
            ->withHeader('Idempotency-Key', 'test-002')
            ->postJson('/api/v1/events', $this->validPayload());

        $response->assertAccepted()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('event.event_type', 'payment.created');

        $this->assertDatabaseHas('webhook_events', [
            'api_client_id' => $client->id,
            'idempotency_key' => 'test-002',
            'event_type' => 'payment.created',
        ]);

        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_repeating_same_event_does_not_create_duplicate(): void
    {
        [, $apiKey] = $this->createApiClient();

        $payload = $this->validPayload();

        $this->withToken($apiKey)
            ->withHeader('Idempotency-Key', 'test-003')
            ->postJson('/api/v1/events', $payload)
            ->assertAccepted();

        $this->withToken($apiKey)
            ->withHeader('Idempotency-Key', 'test-003')
            ->postJson('/api/v1/events', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_same_idempotency_key_with_different_content_returns_conflict(): void
    {
        [, $apiKey] = $this->createApiClient();

        $originalPayload = $this->validPayload();

        $this->withToken($apiKey)
            ->withHeader('Idempotency-Key', 'test-004')
            ->postJson('/api/v1/events', $originalPayload)
            ->assertAccepted();

        $differentPayload = $this->validPayload();
        $differentPayload['payload']['amount'] = 150.00;

        $this->withToken($apiKey)
            ->withHeader('Idempotency-Key', 'test-004')
            ->postJson('/api/v1/events', $differentPayload)
            ->assertConflict()
            ->assertJson([
                'message' => 'Idempotency key already used with different content.',
            ]);

        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_inactive_api_client_is_rejected(): void
    {
        [, $apiKey] = $this->createApiClient(active: false);

        $this->withToken($apiKey)
            ->withHeader('Idempotency-Key', 'test-005')
            ->postJson('/api/v1/events', $this->validPayload())
            ->assertUnauthorized();

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_oversized_request_is_rejected(): void
    {
        [, $apiKey] = $this->createApiClient();

        $payload = [
            'event_type' => 'payment.created',
            'payload' => [
                'data' => str_repeat('x', 70 * 1024),
            ],
        ];

        $this->withToken($apiKey)
            ->withHeader('Idempotency-Key', 'test-006')
            ->postJson('/api/v1/events', $payload)
            ->assertStatus(413)
            ->assertJson([
                'message' => 'Request payload exceeds the maximum allowed size.',
            ]);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_event_creates_pending_deliveries_for_active_endpoints(): void
    {
        [$client, $apiKey] = $this->createApiClient();

        $endpointOne = $this->createEndpoint($client);
        $endpointTwo = $this->createEndpoint($client);

        $this->createEndpoint($client, active: false);

        $response = $this->withToken($apiKey)
            ->withHeader('Idempotency-Key', 'delivery-001')
            ->postJson('/api/v1/events', $this->validPayload());

        $response->assertAccepted();

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('webhook_deliveries', 2);

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_endpoint_id' => $endpointOne->id,
            'status' => 'pending',
            'attempts' => 0,
        ]);

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_endpoint_id' => $endpointTwo->id,
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    public function test_event_does_not_create_deliveries_for_inactive_endpoints(): void
    {
        [$client, $apiKey] = $this->createApiClient();

        $this->createEndpoint($client, active: false);

        $this->withToken($apiKey)
            ->withHeader('Idempotency-Key', 'delivery-002')
            ->postJson('/api/v1/events', $this->validPayload())
            ->assertAccepted();

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_repeating_event_does_not_create_duplicate_deliveries(): void
    {
        [$client, $apiKey] = $this->createApiClient();

        $this->createEndpoint($client);
        $this->createEndpoint($client);

        $payload = $this->validPayload();

        $this->withToken($apiKey)
            ->withHeader('Idempotency-Key', 'delivery-003')
            ->postJson('/api/v1/events', $payload)
            ->assertAccepted();

        $this->withToken($apiKey)
            ->withHeader('Idempotency-Key', 'delivery-003')
            ->postJson('/api/v1/events', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('webhook_deliveries', 2);
    }
}