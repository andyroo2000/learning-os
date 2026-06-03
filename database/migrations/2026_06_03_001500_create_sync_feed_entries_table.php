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
        Schema::create('sync_feed_entries', function (Blueprint $table) {
            $table->id('checkpoint');
            // The sync feed is user-owned operational replay state, not retained audit history.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 64);
            $table->string('resource_type', 64);
            $table->string('resource_id', 64);
            // Validate with SyncFeedOperation at write boundaries; keep this high-volume table easy to evolve.
            $table->string('operation', 32);
            $table->timestamp('server_recorded_at')->useCurrent();
            $table->json('payload')->nullable();

            // Paginated replay: WHERE user_id = ? AND checkpoint > ?
            $table->index(['user_id', 'checkpoint']);
            // Resource history: WHERE user_id = ? AND domain = ? AND resource_type = ? AND resource_id = ? ORDER BY checkpoint
            $table->index(
                ['user_id', 'domain', 'resource_type', 'resource_id', 'checkpoint'],
                'sfe_resource_history_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_feed_entries');
    }
};
