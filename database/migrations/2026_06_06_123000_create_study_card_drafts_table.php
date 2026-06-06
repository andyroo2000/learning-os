<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const USER_CREATED_ID_INDEX = 'study_card_drafts_user_created_id_idx';

    private const USER_STATUS_UPDATED_ID_INDEX = 'study_card_drafts_user_status_updated_id_idx';

    public function up(): void
    {
        Schema::create('study_card_drafts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('generating');
            $table->string('creation_kind', 32);
            $table->string('card_type', 32);
            // Drafts start from user-supplied seed content; generation fills preview fields later.
            $table->json('prompt_json');
            $table->json('answer_json');
            $table->string('image_placement', 16)->default('none');
            $table->text('image_prompt')->nullable();
            $table->json('preview_audio_json')->nullable();
            $table->string('preview_audio_role', 16)->nullable();
            $table->json('preview_image_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at', 'id'], self::USER_CREATED_ID_INDEX);
            $table->index(['user_id', 'status', 'updated_at', 'id'], self::USER_STATUS_UPDATED_ID_INDEX);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_card_drafts');
    }
};
