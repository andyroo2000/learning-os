<?php

namespace Tests\Feature\Content;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImportConvoLabEpisodesTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDatabase = storage_path('framework/testing/convolab-content-'.uniqid().'.sqlite');
        touch($this->sourceDatabase);
        config([
            'database.connections.convolab_content_test' => [
                'driver' => 'sqlite',
                'database' => $this->sourceDatabase,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('convolab_content_test');
        $this->createSourceSchema();
    }

    protected function tearDown(): void
    {
        DB::purge('convolab_content_test');

        if (isset($this->sourceDatabase) && is_file($this->sourceDatabase)) {
            unlink($this->sourceDatabase);
        }

        parent::tearDown();
    }

    public function test_imports_dialogue_and_script_episode_graphs_from_real_source_table_names(): void
    {
        $targetUser = User::factory()->create(['email' => 'ada@example.com']);
        $ids = $this->seedSourceData();

        $exitCode = Artisan::call('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Imported 2 rows into content_episodes.', $output);
        $this->assertStringContainsString('Imported 1 rows into content_audio_script_media.', $output);

        $this->assertDatabaseHas('content_episodes', [
            'id' => $ids['dialogueEpisode'],
            'user_id' => $targetUser->id,
            'convolab_user_id' => $ids['user'],
            'content_type' => 'dialogue',
        ]);
        $this->assertDatabaseHas('content_audio_script_segments', [
            'id' => $ids['segment'],
            'image_media_id' => $ids['media'],
        ]);
        $this->assertDatabaseCount('content_audio_script_media', 1);
        $this->assertDatabaseHas('content_episode_courses', [
            'episode_id' => $ids['dialogueEpisode'],
            'convolab_course_id' => $ids['course'],
        ]);

        Sanctum::actingAs($targetUser);
        $this->getJson('/api/convolab/episodes')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $ids['scriptEpisode'])
            ->assertJsonPath('0.audioScript.segments.0.imageMedia.id', $ids['media'])
            ->assertJsonPath('0.audioScript.segments.0.imageMedia.sourceFilename', 'scene.png')
            ->assertJsonPath('0.audioScript.renders.0.numericSpeed', 0.85)
            ->assertJsonPath('1.dialogue.sentences.0.text', '猫です。');

        $this->getJson('/api/convolab/episodes/'.$ids['dialogueEpisode'])
            ->assertOk()
            ->assertJsonPath('courseEpisodes.0.courseId', $ids['course']);
    }

    public function test_refuses_a_non_empty_target_without_truncate(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        $this->seedSourceData();

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])->assertSuccessful();

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])
            ->expectsOutputToContain('Target table [content_episode_courses] is not empty; rerun with --truncate.')
            ->assertFailed();

        $this->assertDatabaseCount('content_episodes', 2);
    }

    public function test_rejects_the_target_database_as_the_source(): void
    {
        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => DB::getDefaultConnection(),
        ])
            ->expectsOutputToContain('Source and target databases must differ.')
            ->assertFailed();
    }

    public function test_rejects_duplicate_normalized_source_emails(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        $this->seedSourceData();
        DB::connection('convolab_content_test')->table('User')->insert([
            'id' => (string) Str::uuid(),
            'email' => ' ADA@example.com ',
        ]);

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])
            ->expectsOutputToContain('Source contains duplicate normalized user email [ada@example.com].')
            ->assertFailed();

        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_production_truncate_requires_the_exact_target_confirmation(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        User::factory()->create(['email' => 'ada@example.com']);
        $this->seedSourceData();
        $targetDatabase = DB::connection()->getDatabaseName();

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
            '--truncate' => true,
            '--allow-production' => true,
        ])
            ->expectsOutputToContain(
                "Production replacement requires --production-truncate-confirmation=\"TRUNCATE {$targetDatabase}\".",
            )
            ->assertFailed();

        $this->assertDatabaseCount('content_episodes', 0);
    }

    /** @return array<string, string> */
    private function seedSourceData(): array
    {
        $ids = [
            'user' => (string) Str::uuid(),
            'dialogueEpisode' => (string) Str::uuid(),
            'scriptEpisode' => (string) Str::uuid(),
            'dialogue' => (string) Str::uuid(),
            'speaker' => (string) Str::uuid(),
            'sentence' => (string) Str::uuid(),
            'script' => (string) Str::uuid(),
            'segment' => (string) Str::uuid(),
            'media' => (string) Str::uuid(),
            'unreferencedMedia' => (string) Str::uuid(),
            'render' => (string) Str::uuid(),
            'courseEpisode' => (string) Str::uuid(),
            'course' => (string) Str::uuid(),
        ];
        $source = DB::connection('convolab_content_test');
        $created = '2026-07-20 10:00:00.123';

        $source->table('User')->insert(['id' => $ids['user'], 'email' => 'Ada@Example.com']);
        $source->table('Episode')->insert([
            $this->episodeRow($ids['dialogueEpisode'], $ids['user'], 'dialogue', $created),
            $this->episodeRow($ids['scriptEpisode'], $ids['user'], 'script', '2026-07-20 11:00:00.456'),
        ]);
        $source->table('Dialogue')->insert([
            'id' => $ids['dialogue'],
            'episodeId' => $ids['dialogueEpisode'],
            'createdAt' => $created,
            'updatedAt' => $created,
        ]);
        $source->table('Speaker')->insert([
            'id' => $ids['speaker'], 'dialogueId' => $ids['dialogue'], 'name' => 'Aki',
            'voiceId' => 'ja-JP-Neural2-B', 'voiceProvider' => 'google',
            'proficiency' => 'beginner', 'tone' => 'polite', 'gender' => 'female',
            'color' => 'cyan', 'avatarUrl' => '/api/avatars/voices/aki.jpg',
        ]);
        $source->table('Sentence')->insert([
            'id' => $ids['sentence'], 'dialogueId' => $ids['dialogue'], 'speakerId' => $ids['speaker'],
            'order' => 1, 'text' => '猫です。', 'translation' => 'It is a cat.',
            'metadata' => json_encode(['japanese' => ['kanji' => '猫です。']]),
            'audioUrl' => null, 'startTime' => 0, 'endTime' => 800,
            'startTime_0_7' => null, 'endTime_0_7' => null,
            'startTime_0_85' => null, 'endTime_0_85' => null,
            'startTime_1_0' => null, 'endTime_1_0' => null,
            'variations' => json_encode(['猫だ。']), 'selected' => true,
            'createdAt' => $created, 'updatedAt' => $created,
        ]);
        $source->table('audio_scripts')->insert([
            'id' => $ids['script'], 'episodeId' => $ids['scriptEpisode'], 'status' => 'ready',
            'imageStatus' => 'ready', 'imageErrorMessage' => null, 'voiceId' => 'ja-JP-Neural2-B',
            'voiceProvider' => 'google', 'generationMetadataJson' => json_encode(['model' => 'test']),
            'errorMessage' => null, 'createdAt' => $created, 'updatedAt' => $created,
        ]);
        $source->table('study_media')->insert([
            [
                'id' => $ids['media'], 'userId' => $ids['user'], 'sourceKind' => 'generated',
                'sourceFilename' => 'scene.png', 'normalizedFilename' => 'scene.png', 'mediaKind' => 'image',
                'contentType' => 'image/png', 'storagePath' => 'episode-images/scene.png',
                'publicUrl' => '/uploads/episode-images/scene.png', 'createdAt' => $created, 'updatedAt' => $created,
            ],
            [
                'id' => $ids['unreferencedMedia'], 'userId' => $ids['user'], 'sourceKind' => 'anki_import',
                'sourceFilename' => 'card.png', 'normalizedFilename' => 'card.png', 'mediaKind' => 'image',
                'contentType' => 'image/png', 'storagePath' => 'study-media/card.png',
                'publicUrl' => '/uploads/study-media/card.png', 'createdAt' => $created, 'updatedAt' => $created,
            ],
        ]);
        $source->table('audio_script_segments')->insert([
            'id' => $ids['segment'], 'scriptId' => $ids['script'], 'order' => 1,
            'text' => '猫です。', 'reading' => 'ねこです。', 'translation' => 'It is a cat.',
            'imagePrompt' => 'A cat', 'imageStatus' => 'ready', 'imageErrorMessage' => null,
            'imageMediaId' => $ids['media'], 'imageGeneratedAt' => $created,
            'metadata' => json_encode(['scene' => 1]), 'createdAt' => $created, 'updatedAt' => $created,
        ]);
        $source->table('audio_script_renders')->insert([
            'id' => $ids['render'], 'scriptId' => $ids['script'], 'speed' => '0.85',
            'numericSpeed' => 0.85, 'status' => 'ready', 'audioUrl' => '/audio/script.mp3',
            'timingData' => json_encode([['startTime' => 0, 'endTime' => 800]]),
            'approxDurationSeconds' => 0.8, 'errorMessage' => null,
            'createdAt' => $created, 'updatedAt' => $created,
        ]);
        $source->table('CourseEpisode')->insert([
            'id' => $ids['courseEpisode'], 'courseId' => $ids['course'],
            'episodeId' => $ids['dialogueEpisode'], 'order' => 3,
        ]);

        return $ids;
    }

    /** @return array<string, mixed> */
    private function episodeRow(string $id, string $userId, string $contentType, string $updatedAt): array
    {
        return [
            'id' => $id, 'userId' => $userId, 'title' => ucfirst($contentType).' episode',
            'sourceText' => 'Source text', 'targetLanguage' => 'ja', 'nativeLanguage' => 'en',
            'contentType' => $contentType, 'jlptLevel' => 'N5', 'autoGenerateAudio' => true,
            'status' => 'ready', 'isSampleContent' => false, 'audioUrl' => null,
            'audioSpeed' => 'medium', 'audioUrl_0_7' => null, 'audioUrl_0_85' => null,
            'audioUrl_1_0' => null, 'createdAt' => '2026-07-20 10:00:00.123', 'updatedAt' => $updatedAt,
        ];
    }

    private function createSourceSchema(): void
    {
        $schema = Schema::connection('convolab_content_test');
        $schema->create('User', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('email');
        });
        $schema->create('Episode', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('userId');
            $table->string('title');
            $table->text('sourceText');
            $table->string('targetLanguage');
            $table->string('nativeLanguage');
            $table->string('contentType');
            $table->string('jlptLevel')->nullable();
            $table->boolean('autoGenerateAudio');
            $table->string('status');
            $table->boolean('isSampleContent');
            $table->text('audioUrl')->nullable();
            $table->string('audioSpeed')->nullable();
            $table->text('audioUrl_0_7')->nullable();
            $table->text('audioUrl_0_85')->nullable();
            $table->text('audioUrl_1_0')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('Dialogue', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('episodeId');
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('Speaker', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('dialogueId');
            $table->string('name');
            $table->string('voiceId');
            $table->string('voiceProvider')->nullable();
            $table->string('proficiency');
            $table->string('tone');
            $table->string('gender')->nullable();
            $table->string('color')->nullable();
            $table->text('avatarUrl')->nullable();
        });
        $schema->create('Sentence', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('dialogueId');
            $table->string('speakerId');
            $table->integer('order');
            $table->text('text');
            $table->text('translation');
            $table->json('metadata');
            $table->text('audioUrl')->nullable();
            $table->integer('startTime')->nullable();
            $table->integer('endTime')->nullable();
            foreach (['startTime_0_7', 'endTime_0_7', 'startTime_0_85', 'endTime_0_85', 'startTime_1_0', 'endTime_1_0'] as $column) {
                $table->integer($column)->nullable();
            }
            $table->json('variations')->nullable();
            $table->boolean('selected');
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('Image', fn (Blueprint $table) => $table->string('id')->primary());
        $schema->create('audio_scripts', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('episodeId');
            $table->string('status');
            $table->string('imageStatus');
            $table->text('imageErrorMessage')->nullable();
            $table->string('voiceId');
            $table->string('voiceProvider');
            $table->json('generationMetadataJson')->nullable();
            $table->text('errorMessage')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('study_media', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('userId');
            $table->string('sourceKind');
            $table->string('sourceFilename');
            $table->string('normalizedFilename');
            $table->string('mediaKind');
            $table->string('contentType')->nullable();
            $table->text('storagePath')->nullable();
            $table->text('publicUrl')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('audio_script_segments', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('scriptId');
            $table->integer('order');
            $table->text('text');
            $table->text('reading')->nullable();
            $table->text('translation');
            $table->text('imagePrompt')->nullable();
            $table->string('imageStatus');
            $table->text('imageErrorMessage')->nullable();
            $table->string('imageMediaId')->nullable();
            $table->dateTime('imageGeneratedAt')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('audio_script_renders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('scriptId');
            $table->string('speed');
            $table->double('numericSpeed');
            $table->string('status');
            $table->text('audioUrl')->nullable();
            $table->json('timingData')->nullable();
            $table->double('approxDurationSeconds')->nullable();
            $table->text('errorMessage')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('CourseEpisode', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('courseId');
            $table->string('episodeId');
            $table->integer('order');
        });
    }
}
