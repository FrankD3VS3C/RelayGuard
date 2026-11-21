<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\SecureWebhookHttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LengthException;
use RuntimeException;
use Throwable;

class ProcessWebhookDelivery implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    private const MAX_DELIVERY_ATTEMPTS = 5;

    private const LOCK_SECONDS = 45;

    private const MAX_STORED_RESPONSE_BYTES = 16384;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(
        public readonly int $deliveryId,
    ) {}

    public function handle(SecureWebhookHttpClient $httpClient): void
    {
        $lock = Cache::lock(
            'webhook-delivery:'.$this->deliveryId,
            self::LOCK_SECONDS,
        );

        // Another worker already owns this delivery.
        // Release the job so it can retry after the current worker finishes.
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $delivery = DB::transaction(function (): ?WebhookDelivery {
                $delivery = WebhookDelivery::query()
                    ->lockForUpdate()
                    ->with(['event', 'endpoint'])
                    ->find($this->deliveryId);

                if ($delivery === null || $delivery->status !== 'pending') {
                    return null;
                }

                if (
                    $delivery->next_attempt_at !== null
                    && $delivery->next_attempt_at->isFuture()
                ) {
                    $deliveryId = $delivery->id;
                    $nextAttemptAt = $delivery->next_attempt_at;

                    DB::afterCommit(static function () use (
                        $deliveryId,
                        $nextAttemptAt,
                    ): void {
                        self::dispatch($deliveryId)->delay($nextAttemptAt);
                    });

                    return null;
                }

                if (! $delivery->endpoint->is_active) {
                    $delivery->forceFill([
                        'status' => 'failed',
                        'last_error' => 'Webhook endpoint is inactive.',
                        'next_attempt_at' => null,
                    ])->save();

                    return null;
                }

                if ($delivery->attempts >= self::MAX_DELIVERY_ATTEMPTS) {
                    $delivery->forceFill([
                        'status' => 'failed',
                        'last_error' => 'Maximum delivery attempts exceeded.',
                        'next_attempt_at' => null,
                    ])->save();

                    return null;
                }

                $delivery->forceFill([
                    'attempts' => $delivery->attempts + 1,
                    'next_attempt_at' => null,
                ])->save();

                return $delivery->fresh(['event', 'endpoint']);
            });

            if ($delivery === null) {
                return;
            }

            $event = $delivery->event;
            $endpoint = $delivery->endpoint;

            $body = json_encode([
                'id' => $event->id,
                'type' => $event->event_type,
                'created_at' => $event->received_at->utc()->toIso8601String(),
                'data' => $event->payload,
            ], JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR);

            $signature = hash_hmac(
                'sha256',
                $body,
                $endpoint->signing_secret,
            );

            try {
                $response = $httpClient->send(
                    $endpoint->url,
                    $body,
                    [
                        'X-RelayGuard-Signature' => 'sha256='.$signature,
                        'X-RelayGuard-Delivery-Id' => (string) $delivery->id,
                    ],
                );
            } catch (InvalidArgumentException|LengthException $exception) {
                $delivery->forceFill([
                    'status' => 'failed',
                    'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                    'next_attempt_at' => null,
                ])->save();

                return;
            } catch (GuzzleException $exception) {
                $this->recordRetryableError($delivery, $exception);

                throw $exception;
            }

            $status = $response->getStatusCode();

            $responseBody = $response->getBody()->getContents();

            $responseBody = mb_strcut(
                $responseBody,
                0,
                self::MAX_STORED_RESPONSE_BYTES,
                'UTF-8',
            );

            if ($status >= 200 && $status < 300) {
                $delivery->forceFill([
                    'status' => 'delivered',
                    'http_status' => $status,
                    'response_body' => $responseBody,
                    'last_error' => null,
                    'next_attempt_at' => null,
                    'delivered_at' => now(),
                ])->save();

                return;
            }

            if ($status === 408 || $status === 429 || $status >= 500) {
                $exception = new RuntimeException(
                    "Webhook endpoint returned retryable HTTP status {$status}."
                );

                $delivery->forceFill([
                    'http_status' => $status,
                    'response_body' => $responseBody,
                ])->save();

                $this->recordRetryableError($delivery, $exception);

                throw $exception;
            }

            $delivery->forceFill([
                'status' => 'failed',
                'http_status' => $status,
                'response_body' => $responseBody,
                'last_error' => "Webhook endpoint returned HTTP status {$status}.",
                'next_attempt_at' => null,
            ])->save();
        } finally {
            $lock->release();
        }
    }

    private function recordRetryableError(
        WebhookDelivery $delivery,
        Throwable $exception,
    ): void {
        $delays = $this->backoff();
        $delay = $delays[min($delivery->attempts - 1, count($delays) - 1)];

        $delivery->forceFill([
            'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            'next_attempt_at' => now()->addSeconds($delay),
        ])->save();
    }

    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function failed(?Throwable $exception): void
    {
        WebhookDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'last_error' => mb_substr(
                    $exception?->getMessage() ?? 'Webhook delivery failed.',
                    0,
                    2000,
                ),
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
    }
}
