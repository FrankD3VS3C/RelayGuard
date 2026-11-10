<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('idempotency_key', 128);
            $table->string('event_type', 150);
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamps();
            $table->unique(
                ['api_client_id', 'idempotency_key'],
                'webhook_events_client_idempotency_unique'
            );

            $table->index(['event_type', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
