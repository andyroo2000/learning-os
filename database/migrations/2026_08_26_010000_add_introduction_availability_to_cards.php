<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->timestamp('introduction_available_at', 6)->nullable();
            $table->index(
                ['deck_id', 'deleted_at', 'study_status', 'introduction_available_at', 'new_queue_position', 'id'],
                'cards_new_availability_queue_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropIndex('cards_new_availability_queue_idx');
            $table->dropColumn('introduction_available_at');
        });
    }
};
