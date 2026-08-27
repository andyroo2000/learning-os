<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievement_awards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('achievement_id', 128);
            $table->timestampTz('earned_at', 6);
            $table->timestampsTz(6);

            $table->unique(['user_id', 'achievement_id'], 'achievement_awards_user_badge_unique');
            $table->index(
                ['user_id', 'earned_at', 'id'],
                'achievement_awards_user_earned_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievement_awards');
    }
};
