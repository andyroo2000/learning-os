<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wanikani_connections', function (Blueprint $table): void {
            $table->timestamp('transfer_bridge_seeded_at', 6)->nullable();
        });

        Schema::table('user_wanikani_assignments', function (Blueprint $table): void {
            $table->timestamp('transfer_bridge_queued_at', 6)->nullable();
            $table->index(
                ['user_id', 'transfer_bridge_queued_at', 'passed_at', 'subject_id'],
                'wk_assignments_transfer_queue_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('user_wanikani_assignments', function (Blueprint $table): void {
            $table->dropIndex('wk_assignments_transfer_queue_idx');
            $table->dropColumn('transfer_bridge_queued_at');
        });

        Schema::table('wanikani_connections', function (Blueprint $table): void {
            $table->dropColumn('transfer_bridge_seeded_at');
        });
    }
};
