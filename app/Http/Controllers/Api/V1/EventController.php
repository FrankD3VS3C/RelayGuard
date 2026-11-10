<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebhookEventRequest;
use App\Models\ApiClient;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use JsonException;
use Illuminate\Support\Facades\DB;

class EventController extends Controller
{
    /**
     * Store an incoming webhook event.
     *
     * Repeated requests with the same idempotency key and content
     * return the original event instead of creating another record.
     *
     * @throws JsonException
     */
    public function store(
        StoreWebhookEventRequest $request
    ): JsonResponse {
        /** @var ApiClient $apiClient */
        $apiClient = $request->attributes->get('api_client');

        $data = $request->validated();

        $existingEvent = WebhookEvent::query()
            ->where('api_client_id', $apiClient->id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();

        if ($existingEvent) {
            return $this->handleExistingEvent(
                $existingEvent,
                $data['event_type'],
                $data['payload']
            );
        }

        try {
            $event = DB::transaction(function () use (
                $apiClient,
                $data
            ): WebhookEvent {
                // 1. Store the incoming event.
                $event = WebhookEvent::create([
                    'api_client_id' => $apiClient->id,
                    'idempotency_key' => $data['idempotency_key'],
                    'event_type' => $data['event_type'],
                    'payload' => $data['payload'],
                    'received_at' => now(),
                ]);

                // 2. Find this client's active webhook endpoints.
                $endpointIds = $apiClient->endpoints()
                    ->where('is_active', true)
                    ->pluck('id');

                // 3. Create one pending delivery for each endpoint.
                $event->deliveries()->createMany(
                    $endpointIds->map(
                        fn ($endpointId) => [
                            'webhook_endpoint_id' => $endpointId,
                            'status' => 'pending',
                        ]
                    )->all()
                );

                return $event;
            });
        } catch (QueryException $exception) {
            // A concurrent request may have inserted this key first.
            $existingEvent = WebhookEvent::query()
                ->where('api_client_id', $apiClient->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if (!$existingEvent) {
                throw $exception;
            }

            return $this->handleExistingEvent(
                $existingEvent,
                $data['event_type'],
                $data['payload']
            );
        }

        return response()->json([
            'message' => 'Event accepted.',
            'duplicate' => false,
            'event' => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'received_at' => $event->received_at,
            ],
        ], 202);
    }

    private function handleExistingEvent(
        WebhookEvent $event,
        string $eventType,
        array $payload
    ): JsonResponse {
        if (!$this->hasSameContent($event, $eventType, $payload)) {
            return response()->json([
                'message' => 'Idempotency key already used with different content.',
            ], 409);
        }

        return response()->json([
            'message' => 'Event already accepted.',
            'duplicate' => true,
            'event' => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'received_at' => $event->received_at,
            ],
        ], 200);
    }

    private function hasSameContent(
        WebhookEvent $event,
        string $eventType,
        array $payload
    ): bool {
        return hash_equals(
            $this->fingerprint($event->event_type, $event->payload),
            $this->fingerprint($eventType, $payload)
        );
    }

    /**
     * Generate a stable fingerprint independent of JSON object key order.
     *
     * @throws JsonException
     */
    private function fingerprint(
        string $eventType,
        array $payload
    ): string {
        $canonicalPayload = $this->canonicalize($payload);

        $encoded = json_encode(
            [
                'event_type' => $eventType,
                'payload' => $canonicalPayload,
            ],
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );

        return hash('sha256', $encoded);
    }

    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn ($item) => is_array($item)
                    ? $this->canonicalize($item)
                    : $item,
                $value
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}