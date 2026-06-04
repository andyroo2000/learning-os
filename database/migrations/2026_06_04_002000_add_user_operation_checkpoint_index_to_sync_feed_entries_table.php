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
        Schema::table('sync_feed_entries', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'operation', 'checkpoint'],
                'sfe_user_operation_checkpoint_idx',
            );
            $table->index(
                ['user_id', 'domain', 'operation', 'checkpoint'],
                'sfe_user_domain_operation_checkpoint_idx',
            );
            $table->index(
                ['user_id', 'domain', 'resource_type', 'operation', 'checkpoint'],
                'sfe_user_domain_type_operation_checkpoint_idx',
            );
            $table->index(
                ['user_id', 'domain', 'resource_type', 'resource_id', 'operation', 'checkpoint'],
                'sfe_resource_operation_history_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_feed_entries', function (Blueprint $table): void {
            $table->dropIndex('sfe_resource_operation_history_idx');
            $table->dropIndex('sfe_user_domain_type_operation_checkpoint_idx');
            $table->dropIndex('sfe_user_domain_operation_checkpoint_idx');
            $table->dropIndex('sfe_user_operation_checkpoint_idx');
        });
    }
};
