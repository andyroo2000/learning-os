<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_milestone_profiles', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->timestampTz('initialized_at', 6);
        });

        Schema::create('study_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('milestone_key', 64);
            $table->timestampTz('earned_at', 6);
            $table->timestampTz('presented_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['user_id', 'milestone_key'], 'study_milestones_user_key_unique');
            $table->index(
                ['user_id', 'earned_at', 'id'],
                'study_milestones_user_earned_idx',
            );
            $table->index(
                ['user_id', 'presented_at', 'earned_at', 'id'],
                'study_milestones_user_pending_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_milestones');
        Schema::dropIfExists('study_milestone_profiles');
    }
};
