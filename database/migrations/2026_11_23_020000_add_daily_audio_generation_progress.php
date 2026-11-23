<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_audio_practices', function (Blueprint $table): void {
            $table->unsignedTinyInteger('generation_progress')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daily_audio_practices', function (Blueprint $table): void {
            $table->dropColumn('generation_progress');
        });
    }
};
