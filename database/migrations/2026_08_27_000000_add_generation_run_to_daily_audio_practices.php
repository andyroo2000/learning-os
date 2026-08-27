<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_audio_practices', function (Blueprint $table): void {
            $table->uuid('generation_run_id')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('daily_audio_practices', function (Blueprint $table): void {
            $table->dropColumn('generation_run_id');
        });
    }
};
