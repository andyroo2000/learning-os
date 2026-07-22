<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_COLUMNS = [
        'id',
        'userId',
        'sentence',
        'translation',
        'targetLanguage',
        'nativeLanguage',
        'jlptLevel',
        'l1VoiceId',
        'l2VoiceId',
        'promptTemplate',
        'unitsJson',
        'rawResponse',
        'estimatedDurationSecs',
        'parseError',
        'createdAt',
    ];

    public function up(): void
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
                ['created_at', 'id'],
                'admin_sentence_script_tests_order_idx',
            );
        });

        $this->copyLegacyTests();
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_sentence_script_tests');
    }

    private function copyLegacyTests(): void
    {
        if (! Schema::hasTable('sentence_script_tests')
            || ! Schema::hasColumns('sentence_script_tests', self::LEGACY_COLUMNS)) {
            return;
        }

        DB::table('sentence_script_tests')
            ->select([
                'id',
                'userId as actor_convolab_user_id',
                'sentence',
                'translation',
                'targetLanguage as target_language',
                'nativeLanguage as native_language',
                'jlptLevel as jlpt_level',
                'l1VoiceId as l1_voice_id',
                'l2VoiceId as l2_voice_id',
                'promptTemplate as prompt_template',
                'unitsJson as units_json',
                'rawResponse as raw_response',
                'estimatedDurationSecs as estimated_duration_secs',
                'parseError as parse_error',
                'createdAt as created_at',
            ])
            ->orderBy('id')
            ->chunk(500, function ($rows): void {
                DB::table('admin_sentence_script_tests')->insertOrIgnore(
                    $rows->map(fn (object $row): array => (array) $row)->all(),
                );
            });
    }
};
