<?php

namespace Tests\Unit\Services\Webhooks;

use App\Jobs\ProcessWebhookDelivery;
use App\Models\ApiClient;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Services\Webhooks\WebhookDeliveryDispatcher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookDeliveryDispatcherTest extends TestCase
{
    use DatabaseMigrations;

    public function test_it_dispatches_eligible_pending_deliveries(): void
    {
        Queue::fake();

        $withoutPreviousDispatch = $this->createTestDelivery();

        $staleDispatch = $this->createTestDelivery([
            'last_dispatched_at' => now()->subMinutes(6),
        ]);

        $futureRetry = $this->createTestDelivery([
            'next_attempt_at' => now()->addMinute(),
        ]);

        $dispatcher = app(WebhookDeliveryDispatcher::class);

        $dispatched = $dispatcher->dispatchPending();

        $this->assertSame(2, $dispatched);

        Queue::assertPushed(ProcessWebhookDelivery::class, 2);

        $withoutPreviousDispatch->refresh();
        $staleDispatch->refresh();
        $futureRetry->refresh();

        $this->assertNotNull($withoutPreviousDispatch->last_dispatched_at);
        $this->assertNotNull($staleDispatch->last_dispatched_at);
        $this->assertNull($futureRetry->last_dispatched_at);
    }

    public function test_it_skips_recently_dispatched_and_non_pending_deliveries(): void
    {
        Queue::fake();

        $recentDispatch = $this->createTestDelivery([
            'last_dispatched_at' => now()->subMinutes(2),
        ]);

        $alreadyDelivered = $this->createTestDelivery([
            'status' => 'delivered',
            'attempts' => 1,
        ]);

        $dispatcher = app(WebhookDeliveryDispatcher::class);

        $dispatched = $dispatcher->dispatchPending();

        $this->assertSame(0, $dispatched);

        Queue::assertNothingPushed();

        $recentDispatch->refresh();
        $alreadyDelivered->refresh();

        $this->assertSame(
            'pending',
            $recentDispatch->status
        );

        $this->assertSame(
            'delivered',
            $alreadyDelivered->status
        );
    }

    public function test_it_respects_the_requested_batch_limit(): void
    {
        Queue::fake();

        $this->createTestDelivery();
        $this->createTestDelivery();
        $this->createTestDelivery();

        $dispatcher = app(WebhookDeliveryDispatcher::class);

        $dispatched = $dispatcher->dispatchPending(2);

        $this->assertSame(2, $dispatched);

        Queue::assertPushed(ProcessWebhookDelivery::class, 2);
    }

    private function createTestDelivery(
        array $deliveryAttributes = []
    ): WebhookDelivery {
        $client = ApiClient::create([
            'name' => 'Dispatcher test client',
            'key_hash' => hash(
                'sha256',
                bin2hex(random_bytes(32))
            ),
            'is_active' => true,
        ]);

        $event = WebhookEvent::create([
            'api_client_id' => $client->id,
            'idempotency_key' => bin2hex(random_bytes(16)),
            'event_type' => 'test.event',
            'payload' => ['test' => true],
            'received_at' => now(),
        ]);

        $endpoint = WebhookEndpoint::create([
            'api_client_id' => $client->id,
            'name' => 'Dispatcher test endpoint',
            'url' => 'https://example.com/webhook',
            'signing_secret' => bin2hex(random_bytes(32)),
            'is_active' => true,
        ]);

        return WebhookDelivery::create(array_merge([
            'webhook_event_id' => $event->id,
            'webhook_endpoint_id' => $endpoint->id,
            'status' => 'pending',
            'attempts' => 0,
        ], $deliveryAttributes));
    }
}
