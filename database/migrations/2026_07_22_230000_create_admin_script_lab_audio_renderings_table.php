<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_script_lab_audio_renderings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('actor_convolab_user_id');
            $table->text('original_text');
            $table->text('synthesized_text');
            $table->string('voice_id');
            $table->double('speed')->default(1);
            $table->string('format')->nullable();
            $table->double('duration_seconds')->nullable();
            $table->text('audio_storage_path');
            $table->timestampTz('created_at', 3);

            $table->index(
                ['actor_convolab_user_id', 'created_at'],
                'admin_script_lab_audio_actor_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_script_lab_audio_renderings');
    }
};
