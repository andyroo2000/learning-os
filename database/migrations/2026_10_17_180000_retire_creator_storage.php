<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        // Repeat cancellation after old producers and workers have left service.
        (require __DIR__.'/2026_10_17_170000_cancel_retired_content_generation.php')->up();

        // Deploy only after the creator and achievement callers have retired.
        // This prefix belongs exclusively to discarded admin ScriptLab experiments.
        if (! Storage::disk('media')->deleteDirectory('admin-script-lab-audio')) {
            throw new RuntimeException('Unable to delete retired ScriptLab audio.');
        }
        foreach ([
            'content_generation_requests',
            'content_audio_script_generation_jobs',
            'content_image_generation_jobs',
            'content_audio_generation_jobs',
            'content_dialogue_generation_jobs',
            'admin_script_lab_audio_renderings',
            'admin_sentence_script_tests',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('feature_flags', function (Blueprint $table): void {
            $table->dropColumn(['dialoguesEnabled', 'scriptsEnabled', 'audioCourseEnabled']);
        });
    }

    public function down(): void
    {
        // Only empty schemas are restored. Deleted jobs, flags and audio are not recoverable.
        Schema::table('feature_flags', function (Blueprint $table): void {
            foreach (['dialoguesEnabled', 'scriptsEnabled', 'audioCourseEnabled'] as $column) {
                $table->boolean($column)->default(true);
            }
        });
        $this->restoreContentGenerationRequests();
        $this->restoreAudioJobTables();
        $this->restoreContentImageGenerationJobs();
        $this->restoreContentDialogueGenerationJobs();
        $this->restoreAdminScriptLabAudioRenderings();
        $this->restoreAdminSentenceScriptTests();
        Schema::table('content_generation_requests', function (Blueprint $table): void {
            $table->index(['state', 'finished_at'], 'content_gen_requests_retention_idx');
        });
    }

    private function restoreContentGenerationRequests(): void
    {
        Schema::create('content_generation_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('convolab_user_id');
            $table->uuid('client_request_id');
            $table->string('operation', 32);
            $table->string('resource_type', 32);
            $table->uuid('resource_id');
            $table->char('input_fingerprint', 64);
            $table->json('input_payload');
            $table->string('state', 32);
            $table->uuid('job_id')->nullable();
            $table->unsignedInteger('job_attempt')->nullable();
            $table->uuid('dispatch_token')->nullable();
            $table->timestampTz('dispatch_claimed_at', 3)->nullable();
            $table->timestampTz('dispatched_at', 3)->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('finished_at', 3)->nullable();
            $table->timestampsTz(3);

            $table->foreign('convolab_user_id', 'content_gen_requests_owner_fk')
                ->references('convolab_id')
                ->on('admin_user_projections')
                ->cascadeOnDelete();
            $table->unique(
                ['convolab_user_id', 'client_request_id'],
                'content_gen_requests_owner_client_unique',
            );
            $table->index(
                ['state', 'dispatch_claimed_at'],
                'content_gen_requests_recovery_idx',
            );
            $table->index(
                ['operation', 'job_id', 'job_attempt'],
                'content_gen_requests_job_idx',
            );
        });
    }

    private function restoreAudioJobs(string $name, Closure $configure): void
    {
        Schema::create($name, function (Blueprint $table) use ($configure): void {
            $this->addAttemptStateInput($table);
            $this->addGenerationJobFields($table);
            $table->json('result')->nullable();
            $configure($table);
        });
    }

    private function addAttemptStateInput(Blueprint $table): void
    {
        $table->unsignedInteger('attempt');
        $table->string('state', 32)->default('waiting');
        $table->unsignedSmallInteger('progress')->default(0);
        $table->json('input');
    }

    private function addGenerationJobFields(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('episode_id')->constrained('content_episodes')->cascadeOnDelete();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->uuid('convolab_user_id');
        $table->text('error_message')->nullable();
        $table->timestampTz('started_at')->nullable();
        $table->timestampTz('finished_at')->nullable();
        $table->timestampsTz();
    }

    private function restoreAudioJobTables(): void
    {
        foreach (['content_audio_script_generation_jobs', 'content_audio_generation_jobs'] as $name) {
            $this->restoreAudioJobs($name, function (Blueprint $table) use ($name): void {
                if ($name === 'content_audio_script_generation_jobs') {
                    $table->foreignUuid('script_id')->constrained('content_audio_scripts')->cascadeOnDelete();
                    $table->string('kind', 16);

                    $table->unique(['script_id', 'kind', 'attempt'], 'content_script_jobs_script_kind_attempt_unique');
                    $table->index(['script_id', 'state'], 'content_script_jobs_script_state_idx');
                    $table->index(['user_id', 'state'], 'content_script_jobs_user_state_idx');
                } else {
                    $table->foreignUuid('dialogue_id')->constrained('content_dialogues')->cascadeOnDelete();

                    $table->unique(['episode_id', 'attempt'], 'content_audio_jobs_episode_attempt_unique');
                    $table->index('dialogue_id', 'content_audio_jobs_dialogue_idx');
                    $table->index(['user_id', 'state'], 'content_audio_jobs_user_state_idx');
                }
            });
        }
    }

    private function restoreContentImageGenerationJobs(): void
    {
        Schema::create('content_image_generation_jobs', function (Blueprint $table): void {
            $this->addGenerationJobFields($table);
            $table->foreignUuid('dialogue_id')->constrained('content_dialogues')->cascadeOnDelete();
            $table->string('state', 32);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedTinyInteger('image_count');
            $table->uuid('claim_token')->nullable();
            $table->json('result')->nullable();

            $table->index(['dialogue_id', 'state'], 'content_image_jobs_dialogue_state_idx');
        });
    }

    private function restoreContentDialogueGenerationJobs(): void
    {
        Schema::create('content_dialogue_generation_jobs', function (Blueprint $table): void {
            $this->addAttemptStateInput($table);
            $this->addGenerationJobFields($table);

            $table->unique(['episode_id', 'attempt'], 'content_dialogue_jobs_episode_attempt_unique');
            $table->index('user_id', 'content_dialogue_jobs_user_idx');
        });
    }

    private function restoreAdminScriptLabAudioRenderings(): void
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

    private function restoreAdminSentenceScriptTests(): void
    {
        Schema::create('admin_sentence_script_tests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('actor_convolab_user_id');
            $table->text('sentence');
            $table->text('translation')->nullable();
            $table->string('target_language', 16)->default('ja');
            $table->string('native_language', 16)->default('en');
            $table->string('jlpt_level', 32)->nullable();
            $table->string('l1_voice_id');
            $table->string('l2_voice_id');
            $table->text('prompt_template');
            $table->json('units_json')->nullable();
            $table->text('raw_response');
            $table->double('estimated_duration_secs')->nullable();
            $table->text('parse_error')->nullable();
            $table->timestampTz('created_at', 3);

            $table->index(
                ['actor_convolab_user_id', 'created_at', 'id'],
                'admin_sentence_script_tests_actor_order_idx',
            );
        });
    }
};
