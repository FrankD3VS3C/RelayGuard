<?php

namespace Tests\Feature\Console;

use App\Services\Webhooks\WebhookDeliveryDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecoverWebhookDeliveriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recovers_pending_deliveries_using_the_default_limit(): void
    {
        $dispatcher = $this->mock(WebhookDeliveryDispatcher::class);

        $dispatcher->shouldReceive('dispatchPending')
            ->once()
            ->with(100)
            ->andReturn(3);

        $this->artisan('relayguard:deliveries:recover')
            ->expectsOutput('Dispatched 3 pending webhook deliveries.')
            ->assertExitCode(0);
    }

    public function test_it_accepts_a_custom_batch_limit(): void
    {
        $dispatcher = $this->mock(WebhookDeliveryDispatcher::class);

        $dispatcher->shouldReceive('dispatchPending')
            ->once()
            ->with(25)
            ->andReturn(2);

        $this->artisan('relayguard:deliveries:recover', [
            '--limit' => 25,
        ])
            ->expectsOutput('Dispatched 2 pending webhook deliveries.')
            ->assertExitCode(0);
    }

    public function test_it_rejects_invalid_batch_limits(): void
    {
        foreach (['abc', 0, 501] as $limit) {
            $this->artisan('relayguard:deliveries:recover', [
                '--limit' => $limit,
            ])
                ->expectsOutput(
                    'The limit must be an integer between 1 and 500.'
                )
                ->assertExitCode(1);
        }
    }
}
