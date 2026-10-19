<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_activity_daily_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('algorithm_version');
            $table->date('local_date');
            $table->timestampTz('started_at', 6);
            $table->timestampTz('ended_at', 6);
            $table->json('categories');
            $table->timestampsTz(6);
            $table->unique(
                ['user_id', 'timezone', 'algorithm_version', 'local_date'],
                'study_activity_day_unique',
            );
            $table->index(['user_id', 'ended_at'], 'study_activity_day_end_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_activity_daily_summaries');
    }
};
