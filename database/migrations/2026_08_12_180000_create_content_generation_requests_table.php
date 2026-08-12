<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const OWNER_FOREIGN_KEY = 'content_gen_requests_owner_fk';

    public const OWNER_CLIENT_UNIQUE = 'content_gen_requests_owner_client_unique';

    public const RECOVERY_INDEX = 'content_gen_requests_recovery_idx';

    public const JOB_INDEX = 'content_gen_requests_job_idx';

    public function up(): void
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

            $table->foreign('convolab_user_id', self::OWNER_FOREIGN_KEY)
                ->references('convolab_id')
                ->on('admin_user_projections')
                ->cascadeOnDelete();
            $table->unique(
                ['convolab_user_id', 'client_request_id'],
                self::OWNER_CLIENT_UNIQUE,
            );
            $table->index(
                ['state', 'dispatch_claimed_at'],
                self::RECOVERY_INDEX,
            );
            $table->index(
                ['operation', 'job_id', 'job_attempt'],
                self::JOB_INDEX,
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_generation_requests');
    }
};
