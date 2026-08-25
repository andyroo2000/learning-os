<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'cards_variant_group_id_index';

    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            // Progression reads fetch one complete family by its selective group ID;
            // stage and status are evaluated after the family rows are locked.
            $table->index('variant_group_id', self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
};
