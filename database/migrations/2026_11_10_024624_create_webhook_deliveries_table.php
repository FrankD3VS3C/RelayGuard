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
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('webhook_event_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('webhook_endpoint_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('status', 30)->default('pending');

            $table->unsignedSmallInteger('attempts')->default(0);

            $table->unsignedSmallInteger('http_status')->nullable();

            $table->text('response_body')->nullable();

            $table->text('last_error')->nullable();

            $table->timestamp('next_attempt_at')->nullable();

            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['webhook_event_id', 'webhook_endpoint_id'],
                'webhook_deliveries_event_endpoint_unique'
            );

            $table->index(['status', 'next_attempt_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
