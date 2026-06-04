<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DUE_LIST_INDEX = 'cards_deleted_due_id_idx';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->index(
                ['deleted_at', 'due_at', 'id'],
                self::DUE_LIST_INDEX,
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(self::DUE_LIST_INDEX);
        });
    }
};
