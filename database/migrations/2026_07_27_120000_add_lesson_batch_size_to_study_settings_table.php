<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_LESSON_BATCH_SIZE = 5;

    public function up(): void
    {
        Schema::table('study_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('lesson_batch_size')
                ->default(self::DEFAULT_LESSON_BATCH_SIZE)
                ->after('new_cards_per_day');
        });
    }

    public function down(): void
    {
        Schema::table('study_settings', function (Blueprint $table) {
            $table->dropColumn('lesson_batch_size');
        });
    }
};
