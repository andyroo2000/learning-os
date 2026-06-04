<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEW_QUEUE_INDEX = 'cards_deleted_study_new_pos_id_idx';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->unsignedInteger('new_queue_position')
                ->nullable()
                ->after('last_reviewed_at');

            $table->index(
                ['deleted_at', 'study_status', 'new_queue_position', 'id'],
                self::NEW_QUEUE_INDEX,
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(self::NEW_QUEUE_INDEX);
            $table->dropColumn('new_queue_position');
        });
    }
};
