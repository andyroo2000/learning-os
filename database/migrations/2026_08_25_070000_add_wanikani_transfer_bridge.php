<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wanikani_connections', function (Blueprint $table): void {
            // Existing connections remain opt-in because this feature creates study cards.
            $table->boolean('transfer_bridge_enabled')->default(false);
            $table->timestamp('transfer_bridge_enabled_at', 6)->nullable();
            $table->timestamp('transfer_bridge_last_imported_at', 6)->nullable();
            $table->index(
                ['transfer_bridge_enabled', 'id'],
                'wk_connections_transfer_enabled_id_idx',
            );
        });

        Schema::table('study_vocab_variant_groups', function (Blueprint $table): void {
            // The group is the durable progression/provenance boundary for its cards. Keeping
            // the source subject here makes one DB constraint the automatic-import idempotency guard.
            $table->unsignedBigInteger('wanikani_subject_id')->nullable();
            $table->string('automatic_import_status', 32)->nullable();
            $table->text('automatic_import_error')->nullable();
            $table->timestamp('automatic_imported_at', 6)->nullable();

            $table->foreign('wanikani_subject_id')
                ->references('subject_id')
                ->on('wanikani_subjects')
                ->nullOnDelete();
            $table->unique(
                ['user_id', 'wanikani_subject_id'],
                'study_vocab_groups_user_wk_subject_unique',
            );
            $table->index(
                ['user_id', 'automatic_import_status'],
                'study_vocab_groups_user_auto_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('study_vocab_variant_groups', function (Blueprint $table): void {
            $table->dropForeign(['wanikani_subject_id']);
            $table->dropUnique('study_vocab_groups_user_wk_subject_unique');
            $table->dropIndex('study_vocab_groups_user_auto_status_idx');
            $table->dropColumn([
                'wanikani_subject_id',
                'automatic_import_status',
                'automatic_import_error',
                'automatic_imported_at',
            ]);
        });

        Schema::table('wanikani_connections', function (Blueprint $table): void {
            $table->dropIndex('wk_connections_transfer_enabled_id_idx');
            $table->dropColumn([
                'transfer_bridge_enabled',
                'transfer_bridge_enabled_at',
                'transfer_bridge_last_imported_at',
            ]);
        });
    }
};
