<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Models\AdminSentenceScriptTest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminSentenceScriptLegacyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_copies_legacy_rows_without_mutating_the_source_table(): void
    {
        $id = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $migration = require database_path(
            'migrations/2026_07_22_220000_create_admin_sentence_script_tests_table.php',
        );
        $migration->down();
        Schema::create('sentence_script_tests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('userId');
            $table->text('sentence');
            $table->text('translation')->nullable();
            $table->string('targetLanguage');
            $table->string('nativeLanguage');
            $table->string('jlptLevel')->nullable();
            $table->string('l1VoiceId');
            $table->string('l2VoiceId');
            $table->text('promptTemplate');
            $table->json('unitsJson')->nullable();
            $table->text('rawResponse');
            $table->double('estimatedDurationSecs')->nullable();
            $table->string('parseError')->nullable();
            $table->timestampTz('createdAt', 3);
        });
        DB::table('sentence_script_tests')->insert([
            'id' => $id,
            'userId' => $actorId,
            'sentence' => '東京に行きました',
            'translation' => 'I went to Tokyo.',
            'targetLanguage' => 'ja',
            'nativeLanguage' => 'en',
            'jlptLevel' => 'N4',
            'l1VoiceId' => 'fishaudio:ac934b39586e475b83f3277cd97b5cd4',
            'l2VoiceId' => 'fishaudio:0dff3f6860294829b98f8c4501b2cf25',
            'promptTemplate' => 'Legacy prompt',
            'unitsJson' => json_encode([['type' => 'L2', 'text' => '東京']], JSON_THROW_ON_ERROR),
            'rawResponse' => '{}',
            'estimatedDurationSecs' => 10.5,
            'parseError' => null,
            'createdAt' => '2026-07-22 12:00:00.500',
        ]);

        $migration->up();

        $copied = AdminSentenceScriptTest::query()->sole();
        $this->assertSame($id, $copied->id);
        $this->assertSame($actorId, $copied->actor_convolab_user_id);
        $this->assertSame([['type' => 'L2', 'text' => '東京']], $copied->units_json);
        $this->assertSame(10.5, $copied->estimated_duration_secs);
        $this->assertDatabaseCount('sentence_script_tests', 1);
    }
}
