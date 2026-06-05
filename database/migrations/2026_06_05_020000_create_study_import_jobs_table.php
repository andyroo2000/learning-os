<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_import_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('source_type', 64)->default('anki_colpkg');
            $table->string('source_filename');
            $table->string('source_object_path')->nullable();
            $table->string('source_content_type')->nullable();
            $table->unsignedBigInteger('source_size_bytes')->nullable();
            $table->string('deck_name')->default('Japanese');
            $table->json('preview_json');
            $table->json('summary_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('upload_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'study_import_jobs_user_created_idx');
            $table->index(['user_id', 'status'], 'study_import_jobs_user_status_idx');
            $table->index(['user_id', 'updated_at', 'id'], 'study_import_jobs_user_updated_id_idx');
            $table->index('status', 'study_import_jobs_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_import_jobs');
    }
};
