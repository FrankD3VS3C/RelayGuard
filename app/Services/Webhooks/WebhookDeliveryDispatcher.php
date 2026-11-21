<?php

namespace App\Services\Webhooks;

use App\Jobs\ProcessWebhookDelivery;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;

class WebhookDeliveryDispatcher
{
    private const DISPATCH_COOLDOWN_MINUTES = 5;

    public function dispatch(int $deliveryId): bool
    {
        return DB::transaction(function () use ($deliveryId): bool {
            $delivery = WebhookDelivery::query()
                ->lockForUpdate()
                ->find($deliveryId);

            if ($delivery === null || $delivery->status !== 'pending') {
                return false;
            }

            $now = now();

            if (
                $delivery->next_attempt_at !== null
                && $delivery->next_attempt_at->isAfter($now)
            ) {
                return false;
            }

            if (
                $delivery->last_dispatched_at !== null
                && $delivery->last_dispatched_at->isAfter(
                    $now->copy()->subMinutes(self::DISPATCH_COOLDOWN_MINUTES)
                )
            ) {
                return false;
            }

            $delivery->forceFill([
                'last_dispatched_at' => $now,
            ])->save();

            DB::afterCommit(function () use ($deliveryId): void {
                ProcessWebhookDelivery::dispatch($deliveryId);
            });

            return true;
        });
    }

    public function dispatchPending(int $limit = 100): int
    {
        $now = now();
        $cooldownCutoff = $now->copy()->subMinutes(
            self::DISPATCH_COOLDOWN_MINUTES
        );

        $deliveryIds = WebhookDelivery::query()
            ->where('status', 'pending')
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', $now);
            })
            ->where(function ($query) use ($cooldownCutoff): void {
                $query->whereNull('last_dispatched_at')
                    ->orWhere('last_dispatched_at', '<=', $cooldownCutoff);
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $dispatched = 0;

        foreach ($deliveryIds as $deliveryId) {
            if ($this->dispatch((int) $deliveryId)) {
                $dispatched++;
            }
        }

        return $dispatched;
    }
}
