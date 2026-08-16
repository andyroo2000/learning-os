<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_event_mirrors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('google_calendar_connection_id')->constrained(
                indexName: 'google_calendar_event_mirrors_connection_foreign',
            )->cascadeOnDelete();
            $table->char('source_key', 64);
            $table->string('calendar_id', 1024);
            $table->string('provider_event_id', 1024);
            $table->string('recurring_event_id', 1024)->nullable();
            $table->timestampTz('original_start_at', 6)->nullable();
            $table->string('status', 32);
            $table->text('title')->nullable();
            $table->timestampTz('starts_at', 6)->nullable();
            $table->timestampTz('ends_at', 6)->nullable();
            $table->boolean('all_day')->default(false);
            $table->timestampTz('provider_updated_at', 6)->nullable();
            $table->timestampTz('observed_at', 6);
            $table->timestampsTz(6);

            $table->unique(
                ['google_calendar_connection_id', 'source_key'],
                'google_calendar_event_mirrors_connection_source_unique',
            );
            $table->index(
                ['google_calendar_connection_id', 'status', 'ends_at'],
                'google_calendar_event_mirrors_reconcile_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_event_mirrors');
    }
};
