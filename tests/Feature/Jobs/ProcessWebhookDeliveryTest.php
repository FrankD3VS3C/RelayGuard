<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessWebhookDelivery;
use App\Models\ApiClient;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Services\Webhooks\SecureWebhookHttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProcessWebhookDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_delivers_a_webhook_and_signs_the_exact_request_body(): void
    {
        $secret = 'test-signing-secret';

        $client = ApiClient::create([
            'name' => 'Test client',
            'key_hash' => hash('sha256', 'test-api-key'),
            'is_active' => true,
        ]);

        $event = WebhookEvent::create([
            'api_client_id' => $client->id,
            'idempotency_key' => 'test-event-001',
            'event_type' => 'order.created',
            'payload' => [
                'order_id' => 123,
                'amount' => 19.95,
            ],
            'received_at' => now()->subMinute(),
        ]);

        $endpoint = WebhookEndpoint::create([
            'api_client_id' => $client->id,
            'name' => 'Test endpoint',
            'url' => 'https://example.com/webhooks/test',
            'signing_secret' => $secret,
            'is_active' => true,
        ]);

        $delivery = WebhookDelivery::create([
            'webhook_event_id' => $event->id,
            'webhook_endpoint_id' => $endpoint->id,
            'status' => 'pending',
            'attempts' => 0,
        ]);

        $sentUrl = null;
        $sentBody = null;
        $sentHeaders = null;

        $httpClient = Mockery::mock(SecureWebhookHttpClient::class);

        $httpClient
            ->shouldReceive('send')
            ->once()
            ->andReturnUsing(
                function ($url, $body, $headers) use (
                    &$sentUrl,
                    &$sentBody,
                    &$sentHeaders
                ) {
                    $sentUrl = $url;
                    $sentBody = $body;
                    $sentHeaders = $headers;

                    return new Response(200, [], 'accepted');
                }
            );

        $job = new ProcessWebhookDelivery($delivery->id);

        $job->handle($httpClient);

        $delivery->refresh();

        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(200, $delivery->http_status);
        $this->assertSame('accepted', $delivery->response_body);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertNull($delivery->last_error);
        $this->assertNull($delivery->next_attempt_at);

        $this->assertSame(
            'https://example.com/webhooks/test',
            $sentUrl
        );

        $expectedBody = json_encode([
            'id' => $event->id,
            'type' => 'order.created',
            'created_at' => $event->received_at->utc()->toIso8601String(),
            'data' => [
                'order_id' => 123,
                'amount' => 19.95,
            ],
        ], JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR);

        $this->assertSame($expectedBody, $sentBody);

        $expectedSignature = 'sha256='.hash_hmac(
            'sha256',
            $expectedBody,
            $secret
        );

        $this->assertSame(
            $expectedSignature,
            $sentHeaders['X-RelayGuard-Signature']
        );

        $this->assertSame(
            (string) $delivery->id,
            $sentHeaders['X-RelayGuard-Delivery-Id']
        );
    }

    public function test_it_marks_a_client_error_as_failed_without_retrying(): void
    {
        $delivery = $this->createTestDelivery();

        $httpClient = Mockery::mock(SecureWebhookHttpClient::class);

        $httpClient
            ->shouldReceive('send')
            ->once()
            ->andReturn(new Response(400, [], 'Invalid payload'));

        (new ProcessWebhookDelivery($delivery->id))->handle($httpClient);

        $delivery->refresh();

        $this->assertSame('failed', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(400, $delivery->http_status);
        $this->assertSame('Invalid payload', $delivery->response_body);
        $this->assertNull($delivery->next_attempt_at);
    }

    public function test_it_schedules_a_retry_for_server_errors(): void
    {
        $delivery = $this->createTestDelivery();

        $httpClient = Mockery::mock(SecureWebhookHttpClient::class);

        $httpClient
            ->shouldReceive('send')
            ->once()
            ->andReturn(new Response(503, [], 'Service unavailable'));

        try {
            (new ProcessWebhookDelivery($delivery->id))->handle($httpClient);
            $this->fail('Expected a retryable exception.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('503', $exception->getMessage());
        }

        $delivery->refresh();

        $this->assertSame('pending', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(503, $delivery->http_status);
        $this->assertSame('Service unavailable', $delivery->response_body);
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertTrue($delivery->next_attempt_at->isFuture());
        $this->assertNotNull($delivery->last_error);
    }

    public function test_it_schedules_a_retry_when_the_connection_fails(): void
    {
        $delivery = $this->createTestDelivery();

        $httpClient = Mockery::mock(SecureWebhookHttpClient::class);

        $httpClient
            ->shouldReceive('send')
            ->once()
            ->andThrow(
                new ConnectException(
                    'Connection failed',
                    new Request('POST', 'https://example.com/webhooks/test')
                )
            );

        try {
            (new ProcessWebhookDelivery($delivery->id))->handle($httpClient);
            $this->fail('Expected a connection exception.');
        } catch (GuzzleException $exception) {
            $this->assertInstanceOf(ConnectException::class, $exception);
        }

        $delivery->refresh();

        $this->assertSame('pending', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertTrue($delivery->next_attempt_at->isFuture());
    }

    public function test_it_does_not_send_before_the_next_attempt_time(): void
    {
        $delivery = $this->createTestDelivery([
            'attempts' => 1,
            'next_attempt_at' => now()->addMinute(),
        ]);

        $httpClient = Mockery::mock(SecureWebhookHttpClient::class);

        $httpClient->shouldNotReceive('send');

        (new ProcessWebhookDelivery($delivery->id))->handle($httpClient);

        $delivery->refresh();

        $this->assertSame('pending', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertTrue($delivery->next_attempt_at->isFuture());
    }

    public function test_it_does_not_send_to_an_inactive_endpoint(): void
    {
        $delivery = $this->createTestDelivery([], false);

        $httpClient = Mockery::mock(SecureWebhookHttpClient::class);

        $httpClient->shouldNotReceive('send');

        (new ProcessWebhookDelivery($delivery->id))->handle($httpClient);

        $delivery->refresh();

        $this->assertSame('failed', $delivery->status);
        $this->assertSame(0, $delivery->attempts);
        $this->assertNull($delivery->next_attempt_at);
    }

    public function test_it_does_not_send_an_already_delivered_event(): void
    {
        $delivery = $this->createTestDelivery([
            'status' => 'delivered',
            'attempts' => 1,
        ]);

        $httpClient = Mockery::mock(SecureWebhookHttpClient::class);

        $httpClient->shouldNotReceive('send');

        (new ProcessWebhookDelivery($delivery->id))->handle($httpClient);

        $delivery->refresh();

        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
    }

    private function createTestDelivery(
        array $deliveryAttributes = [],
        bool $endpointActive = true
    ): WebhookDelivery {
        $client = ApiClient::create([
            'name' => 'Test client',
            'key_hash' => hash('sha256', 'test-api-key'),
            'is_active' => true,
        ]);

        $event = WebhookEvent::create([
            'api_client_id' => $client->id,
            'idempotency_key' => 'test-event-'.uniqid(),
            'event_type' => 'order.created',
            'payload' => ['order_id' => 123],
            'received_at' => now()->subMinute(),
        ]);

        $endpoint = WebhookEndpoint::create([
            'api_client_id' => $client->id,
            'name' => 'Test endpoint',
            'url' => 'https://example.com/webhooks/test',
            'signing_secret' => 'test-signing-secret',
            'is_active' => $endpointActive,
        ]);

        return WebhookDelivery::create(array_merge([
            'webhook_event_id' => $event->id,
            'webhook_endpoint_id' => $endpoint->id,
            'status' => 'pending',
            'attempts' => 0,
        ], $deliveryAttributes));
    }

    public function test_it_does_not_exceed_the_maximum_delivery_attempts(): void
    {
        $delivery = $this->createTestDelivery([
            'attempts' => 5,
        ]);

        $httpClient = Mockery::mock(SecureWebhookHttpClient::class);

        $httpClient->shouldNotReceive('send');

        (new ProcessWebhookDelivery($delivery->id))->handle($httpClient);

        $delivery->refresh();

        $this->assertSame('failed', $delivery->status);
        $this->assertSame(5, $delivery->attempts);
        $this->assertSame(
            'Maximum delivery attempts exceeded.',
            $delivery->last_error
        );
        $this->assertNull($delivery->next_attempt_at);
    }

    public function test_failed_callback_marks_pending_delivery_as_failed(): void
    {
        $delivery = $this->createTestDelivery([
            'attempts' => 5,
        ]);

        $job = new ProcessWebhookDelivery($delivery->id);

        $job->failed(new RuntimeException('Final connection failure'));

        $delivery->refresh();

        $this->assertSame('failed', $delivery->status);
        $this->assertSame(5, $delivery->attempts);
        $this->assertSame(
            'Final connection failure',
            $delivery->last_error
        );
        $this->assertNull($delivery->next_attempt_at);
    }

    public function test_it_does_not_process_a_delivery_while_another_worker_holds_its_lock(): void
    {
        $delivery = $this->createTestDelivery();

        $lock = Cache::lock('webhook-delivery:'.$delivery->id, 45);

        $this->assertTrue($lock->get());

        try {
            $httpClient = Mockery::mock(SecureWebhookHttpClient::class);

            $httpClient->shouldNotReceive('send');

            $job = (new ProcessWebhookDelivery($delivery->id))
                ->withFakeQueueInteractions();

            $job->handle($httpClient);

            $job->assertReleased(30);
            $job->assertNotDeleted();

            $delivery->refresh();

            $this->assertSame('pending', $delivery->status);
            $this->assertSame(0, $delivery->attempts);
        } finally {
            $lock->release();
        }
    }

    public function test_it_reschedules_delivery_when_executed_before_next_attempt_at(): void
    {
        Queue::fake();

        $delivery = $this->createTestDelivery([
            'status' => 'pending',
            'attempts' => 1,
            'next_attempt_at' => now()->addMinutes(5),
        ]);

        $scheduledAt = $delivery->next_attempt_at;

        $httpClient = Mockery::mock(SecureWebhookHttpClient::class);

        $httpClient->shouldNotReceive('send');

        $job = new ProcessWebhookDelivery($delivery->id);

        $job->handle($httpClient);

        Queue::assertPushed(
            ProcessWebhookDelivery::class,
            function (ProcessWebhookDelivery $queuedJob) use ($delivery): bool {
                return $queuedJob->deliveryId === $delivery->id;
            },
        );

        $delivery->refresh();

        $this->assertSame('pending', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertEquals(
            $scheduledAt->timestamp,
            $delivery->next_attempt_at->timestamp,
        );
    }

    public function test_it_configures_five_attempts_with_expected_backoff(): void
    {
        $job = new ProcessWebhookDelivery(1);

        $this->assertSame(5, $job->tries);

        $this->assertSame(
            [10, 30, 120, 300],
            $job->backoff(),
        );
    }
}
