<?php

namespace App\Console\Commands;

use App\Services\Webhooks\WebhookDeliveryDispatcher;
use Illuminate\Console\Command;

class RecoverWebhookDeliveries extends Command
{
    protected $signature = 'relayguard:deliveries:recover {--limit=100}';

    protected $description = 'Recover pending webhook deliveries that were not dispatched';

    public function handle(WebhookDeliveryDispatcher $dispatcher): int
    {
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT
        );

        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('The limit must be an integer between 1 and 500.');

            return self::FAILURE;
        }

        $dispatched = $dispatcher->dispatchPending($limit);

        $this->info("Dispatched {$dispatched} pending webhook deliveries.");

        return self::SUCCESS;
    }
}
