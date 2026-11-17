<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Keep costs after entry deletion, but erase them with the owning account.
            $table->ulid('resource_id');
            $table->string('operation', 64);
            $table->string('provider', 32);
            $table->string('model', 128);
            $table->string('provider_request_id')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('duration_ms');
            foreach (['input_tokens', 'cached_input_tokens', 'cache_write_tokens', 'output_tokens', 'reasoning_tokens', 'input_bytes', 'estimated_micro_usd'] as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            $table->json('rate_snapshot')->nullable();
            $table->timestamp('created_at', 6);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
