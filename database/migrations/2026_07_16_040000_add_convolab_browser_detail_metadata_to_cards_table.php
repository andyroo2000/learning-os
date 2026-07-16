<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->string('convolab_note_source_kind', 64)->nullable();
            $table->string('convolab_note_source_guid')->nullable();
            $table->unsignedBigInteger('convolab_note_source_notetype_id')->nullable();
            $table->json('convolab_note_raw_fields_json')->nullable();
            $table->json('convolab_note_canonical_json')->nullable();
            $table->string('source_deck_name')->nullable();
            $table->string('source_template_name')->nullable();
            $table->integer('source_queue')->nullable();
            $table->integer('source_card_type')->nullable();
            $table->integer('source_due')->nullable();
            $table->integer('source_interval')->nullable();
            $table->integer('source_factor')->nullable();
            $table->integer('source_reps')->nullable();
            $table->integer('source_lapses')->nullable();
            $table->integer('source_left')->nullable();
            $table->integer('source_original_due')->nullable();
            $table->unsignedBigInteger('source_original_deck_id')->nullable();
            $table->json('source_fsrs_json')->nullable();
            $table->string('answer_audio_source', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropColumn([
                'convolab_note_source_kind',
                'convolab_note_source_guid',
                'convolab_note_source_notetype_id',
                'convolab_note_raw_fields_json',
                'convolab_note_canonical_json',
                'source_deck_name',
                'source_template_name',
                'source_queue',
                'source_card_type',
                'source_due',
                'source_interval',
                'source_factor',
                'source_reps',
                'source_lapses',
                'source_left',
                'source_original_due',
                'source_original_deck_id',
                'source_fsrs_json',
                'answer_audio_source',
            ]);
        });
    }
};
