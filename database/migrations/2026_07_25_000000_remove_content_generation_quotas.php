<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('content_generation_cooldowns');
        Schema::dropIfExists('generation_logs');
    }

    public function down(): void
    {
        Schema::create('generation_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('userId');
            $table->string('contentType');
            $table->string('contentId')->nullable();
            $table->timestampTz('createdAt', 3);
            $table->index('userId', 'generation_logs_user_id_idx');
            $table->index(['userId', 'createdAt'], 'generation_logs_user_created_idx');
            $table->index('createdAt', 'generation_logs_created_at_idx');
        });

        Schema::create('content_generation_cooldowns', function (Blueprint $table): void {
            $table->uuid('convolab_user_id')->primary();
            $table->uuid('generation_log_id')->nullable();
            $table->timestampTz('available_at', 3);
            $table->foreign('convolab_user_id', 'generation_cooldowns_user_fk')
                ->references('convolab_id')
                ->on('admin_user_projections')
                ->cascadeOnDelete();
        });
    }
};
