<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\ProcessWebhookDelivery;
use App\Models\ApiClient;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchWebhookDeliveryTest extends TestCase
{
    use DatabaseMigrations;

    private function createApiClient(): array
    {
        $plainTextKey = 'rg_test_'.bin2hex(random_bytes(32));

        $client = ApiClient::create([
            'name' => 'Queue Test Client',
            'key_hash' => hash('sha256', $plainTextKey),
            'is_active' => true,
        ]);

        return [$client, $plainTextKey];
    }

    private function createEndpoint(
        ApiClient $client,
        bool $active = true
    ): WebhookEndpoint {
        return WebhookEndpoint::create([
            'api_client_id' => $client->id,
            'name' => 'Queue Test Endpoint',
            'url' => 'https://example.com/webhook',
            'signing_secret' => bin2hex(random_bytes(32)),
            'is_active' => $active,
        ]);
    }

    private function validPayload(): array
    {
        return [
            'event_type' => 'payment.created',
            'payload' => [
                'payment_id' => 'pay_queue_001',
                'amount' => 99.90,
                'currency' => 'EUR',
            ],
        ];
    }

    private function sendEvent(
        string $apiKey,
        string $idempotencyKey
    ) {
        return $this->withToken($apiKey)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/events', $this->validPayload());
    }

    public function test_dispatches_one_job_for_each_active_endpoint(): void
    {
        Queue::fake();

        [$client, $apiKey] = $this->createApiClient();

        $this->createEndpoint($client);
        $this->createEndpoint($client);
        $this->createEndpoint($client, active: false);

        $this->sendEvent($apiKey, 'queue-001')
            ->assertAccepted();

        $deliveries = WebhookDelivery::query()
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $deliveries);

        Queue::assertPushed(
            ProcessWebhookDelivery::class,
            2
        );

        foreach ($deliveries as $delivery) {
            Queue::assertPushed(
                ProcessWebhookDelivery::class,
                fn (ProcessWebhookDelivery $job): bool => $job->deliveryId === $delivery->id
            );
        }
    }

    public function test_does_not_dispatch_jobs_when_there_are_no_active_endpoints(): void
    {
        Queue::fake();

        [$client, $apiKey] = $this->createApiClient();

        $this->createEndpoint($client, active: false);

        $this->sendEvent($apiKey, 'queue-002')
            ->assertAccepted();

        $this->assertDatabaseCount('webhook_deliveries', 0);

        Queue::assertNothingPushed();
    }

    public function test_duplicate_event_does_not_dispatch_additional_jobs(): void
    {
        Queue::fake();

        [$client, $apiKey] = $this->createApiClient();

        $this->createEndpoint($client);
        $this->createEndpoint($client);

        $this->sendEvent($apiKey, 'queue-003')
            ->assertAccepted();

        Queue::assertPushed(
            ProcessWebhookDelivery::class,
            2
        );

        $this->sendEvent($apiKey, 'queue-003')
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        Queue::assertPushed(
            ProcessWebhookDelivery::class,
            2
        );

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('webhook_deliveries', 2);
    }

    public function test_does_not_dispatch_jobs_when_delivery_creation_fails(): void
    {
        Queue::fake();

        [$client, $apiKey] = $this->createApiClient();

        $this->createEndpoint($client);

        $this->withoutExceptionHandling();

        try {
            DB::transaction(
                function () use ($apiKey): void {
                    $this->sendEvent($apiKey, 'queue-004')
                        ->assertAccepted();

                    throw new \RuntimeException(
                        'Simulated outer transaction failure.'
                    );
                }
            );

            $this->fail('Expected the transaction to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Simulated outer transaction failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);

        Queue::assertNothingPushed();
    }
}
