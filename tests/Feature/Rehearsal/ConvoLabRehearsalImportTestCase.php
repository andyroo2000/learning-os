<?php

namespace Tests\Feature\Rehearsal;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

abstract class ConvoLabRehearsalImportTestCase extends TestCase
{
    use RefreshDatabase;

    protected const SOURCE_USER_ID = '21a610da-f528-4b58-afef-8a6f8f847a86';

    protected const SOURCE_OTHER_USER_ID = 'ba860112-41be-4277-89f6-a357633f6dab';

    protected const SOURCE_DUPLICATE_EMAIL_USER_ID = '5d45335f-55e9-467d-a645-13e9018b21c1';

    protected const SOURCE_IMPORT_ID = '98f42a62-8303-410e-ad4d-5a69c55911bb';

    protected const SOURCE_CARD_ID = 'c358732a-2cd0-4b18-9cce-c474297863f9';

    protected const SOURCE_NOTE_ID = '9e33f12d-cf38-409b-bbf1-6fddd9977576';

    protected const SOURCE_DAILY_AUDIO_ID = '33cb3d35-8566-4dd5-aebe-af1725c3d18a';

    protected const SOURCE_DAILY_AUDIO_TRACK_ID = '81469668-5a26-48ad-8bf1-65587c8463ee';

    protected string $sourceDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDatabase = storage_path('framework/testing/convolab-source-'.uniqid().'.sqlite');
        touch($this->sourceDatabase);

        config([
            'database.connections.convolab_test_source' => [
                'driver' => 'sqlite',
                'database' => $this->sourceDatabase,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('convolab_test_source');
        $this->createConvoLabSourceSchema();
    }

    protected function tearDown(): void
    {
        DB::purge('convolab_test_source');

        if (isset($this->sourceDatabase) && is_file($this->sourceDatabase)) {
            unlink($this->sourceDatabase);
        }

        parent::tearDown();
    }

    private function createConvoLabSourceSchema(): void
    {
        $schema = Schema::connection('convolab_test_source');

        $this->createAccountSchema($schema);
        $this->createDailyAudioSchema($schema);
        $this->createImportSchema($schema);
        $this->createStudyContentSchema($schema);
        $this->createReviewSchema($schema);
    }

    private function createAccountSchema(Builder $schema): void
    {
        $schema->create('User', function ($table): void {
            $table->text('id')->primary();
            $table->text('email');
            $table->text('password')->nullable();
            $table->text('name');
            $table->text('displayName')->nullable();
            $table->timestamp('emailVerifiedAt')->nullable();
            $table->timestamp('createdAt');
            $table->timestamp('updatedAt');
        });

        $schema->create('study_settings', function ($table): void {
            $table->text('userId')->primary();
            $table->integer('newCardsPerDay');
            $table->timestamp('createdAt');
            $table->timestamp('updatedAt');
        });
    }

    private function createDailyAudioSchema(Builder $schema): void
    {
        $schema->create('daily_audio_practices', function ($table): void {
            $table->text('id')->primary();
            $table->text('userId');
            $table->date('practiceDate');
            $table->text('status');
            $table->integer('targetDurationMinutes');
            $table->text('targetLanguage');
            $table->text('nativeLanguage');
            $table->json('sourceCardIdsJson')->nullable();
            $table->json('selectionSummaryJson')->nullable();
            $table->text('errorMessage')->nullable();
            $table->timestamp('createdAt');
            $table->timestamp('updatedAt');
        });

        $schema->create('daily_audio_practice_tracks', function ($table): void {
            $table->text('id')->primary();
            $table->text('practiceId');
            $table->text('mode');
            $table->text('status');
            $table->text('title');
            $table->integer('sortOrder');
            $table->json('scriptUnitsJson')->nullable();
            $table->text('audioUrl')->nullable();
            $table->json('timingData')->nullable();
            $table->integer('approxDurationSeconds')->nullable();
            $table->json('generationMetadataJson')->nullable();
            $table->text('errorMessage')->nullable();
            $table->timestamp('createdAt');
            $table->timestamp('updatedAt');
        });
    }

    private function createImportSchema(Builder $schema): void
    {
        $schema->create('study_import_jobs', function ($table): void {
            $table->text('id')->primary();
            $table->text('userId');
            $table->text('status');
            $table->text('sourceType');
            $table->text('sourceFilename');
            $table->text('sourceObjectPath')->nullable();
            $table->text('sourceContentType')->nullable();
            $table->integer('sourceSizeBytes')->nullable();
            $table->text('deckName');
            $table->json('previewJson');
            $table->json('summaryJson')->nullable();
            $table->text('errorMessage')->nullable();
            $table->timestamp('startedAt')->nullable();
            $table->timestamp('uploadedAt')->nullable();
            $table->timestamp('uploadExpiresAt')->nullable();
            $table->timestamp('completedAt')->nullable();
            $table->timestamp('createdAt');
            $table->timestamp('updatedAt');
        });

        $schema->create('study_media', function ($table): void {
            $table->text('id')->primary();
            $table->text('userId');
            $table->text('importJobId')->nullable();
            $table->text('sourceKind');
            $table->text('sourceMediaKey')->nullable();
            $table->text('sourceFilename');
            $table->text('normalizedFilename');
            $table->text('mediaKind');
            $table->text('contentType')->nullable();
            $table->text('storagePath')->nullable();
            $table->text('publicUrl')->nullable();
            $table->timestamp('createdAt');
            $table->timestamp('updatedAt');
        });
    }

    private function createStudyContentSchema(Builder $schema): void
    {
        $schema->create('study_notes', function ($table): void {
            $table->text('id')->primary();
            $table->text('userId');
            $table->text('sourceKind');
            $table->integer('sourceNoteId')->nullable();
            $table->text('sourceGuid')->nullable();
            $table->integer('sourceNotetypeId')->nullable();
            $table->text('sourceNotetypeName')->nullable();
            $table->json('rawFieldsJson');
            $table->json('canonicalJson');
            $table->timestamp('createdAt');
            $table->timestamp('updatedAt');
        });

        $schema->create('study_cards', function ($table): void {
            $table->text('id')->primary();
            $table->text('userId');
            $table->text('noteId');
            $table->text('importJobId')->nullable();
            $table->text('sourceKind');
            $table->integer('sourceCardId')->nullable();
            $table->integer('sourceDeckId')->nullable();
            $table->text('sourceDeckName')->nullable();
            $table->integer('sourceTemplateOrd')->nullable();
            $table->text('sourceTemplateName')->nullable();
            $table->integer('sourceQueue')->nullable();
            $table->integer('sourceCardType')->nullable();
            $table->integer('sourceDue')->nullable();
            $table->integer('sourceInterval')->nullable();
            $table->integer('sourceFactor')->nullable();
            $table->integer('sourceReps')->nullable();
            $table->integer('sourceLapses')->nullable();
            $table->integer('sourceLeft')->nullable();
            $table->integer('sourceOriginalDue')->nullable();
            $table->integer('sourceOriginalDeckId')->nullable();
            $table->json('sourceFsrsJson')->nullable();
            $table->text('cardType');
            $table->text('queueState');
            $table->timestamp('dueAt')->nullable();
            $table->timestamp('lastReviewedAt')->nullable();
            $table->json('promptJson');
            $table->json('answerJson');
            $table->json('schedulerStateJson');
            $table->text('answerAudioSource');
            $table->text('promptAudioMediaId')->nullable();
            $table->text('answerAudioMediaId')->nullable();
            $table->text('imageMediaId')->nullable();
            $table->timestamp('createdAt');
            $table->timestamp('updatedAt');
            $table->text('searchText');
            $table->timestamp('introducedAt')->nullable();
            $table->integer('newQueuePosition')->nullable();
            $table->timestamp('failedAt')->nullable();
            $table->text('variantGroupId')->nullable();
            $table->text('variantKind')->nullable();
            $table->text('variantSentenceId')->nullable();
            $table->integer('variantStage')->nullable();
            $table->text('variantStatus')->nullable();
            $table->timestamp('variantUnlockedAt')->nullable();
        });
    }

    private function createReviewSchema(Builder $schema): void
    {
        $schema->create('study_review_logs', function ($table): void {
            $table->text('id')->primary();
            $table->text('userId');
            $table->text('cardId');
            $table->text('importJobId')->nullable();
            $table->text('source');
            $table->integer('sourceReviewId')->nullable();
            $table->timestamp('reviewedAt');
            $table->integer('rating');
            $table->integer('durationMs')->nullable();
            $table->integer('sourceEase')->nullable();
            $table->integer('sourceInterval')->nullable();
            $table->integer('sourceLastInterval')->nullable();
            $table->integer('sourceFactor')->nullable();
            $table->integer('sourceTimeMs')->nullable();
            $table->integer('sourceReviewType')->nullable();
            $table->json('stateBeforeJson')->nullable();
            $table->json('stateAfterJson')->nullable();
            $table->json('rawPayloadJson')->nullable();
            $table->timestamp('createdAt');
        });
    }

    protected function seedConvoLabSourceData(): void
    {
        $source = DB::connection('convolab_test_source');
        $now = '2026-07-14 10:00:00';

        $this->seedAccount($source, $now);
        $this->seedDailyAudio($source);
        $this->seedImport($source, $now);
        $this->seedMedia($source, $now);
        $this->seedNote($source);
        $this->seedCard($source, $now);
        $this->seedReview($source, $now);
    }

    protected function assertImportFailsWith(string $message): void
    {
        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain($message)
            ->assertExitCode(1);
    }

    private function seedAccount(Connection $source, string $now): void
    {
        $source->table('User')->insert([
            'id' => self::SOURCE_USER_ID,
            'email' => 'ada@example.com',
            'password' => null,
            'name' => 'Ada',
            'displayName' => 'Ada Lovelace',
            'emailVerifiedAt' => $now,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);

        $source->table('study_settings')->insert([
            'userId' => self::SOURCE_USER_ID,
            'newCardsPerDay' => 12,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);
    }

    private function seedDailyAudio(Connection $source): void
    {
        $source->table('daily_audio_practices')->insert([
            'id' => self::SOURCE_DAILY_AUDIO_ID,
            'userId' => self::SOURCE_USER_ID,
            'practiceDate' => '2026-07-14',
            'status' => 'ready',
            'targetDurationMinutes' => 30,
            'targetLanguage' => 'ja',
            'nativeLanguage' => 'en',
            'sourceCardIdsJson' => json_encode([self::SOURCE_CARD_ID]),
            'selectionSummaryJson' => json_encode(['selectedCount' => 1]),
            'errorMessage' => null,
            'createdAt' => '2026-07-14 10:00:00.554',
            'updatedAt' => '2026-07-14 10:00:00.766',
        ]);

        $source->table('daily_audio_practice_tracks')->insert([
            'id' => self::SOURCE_DAILY_AUDIO_TRACK_ID,
            'practiceId' => self::SOURCE_DAILY_AUDIO_ID,
            'mode' => 'drill',
            'status' => 'ready',
            'title' => 'Drill',
            'sortOrder' => 0,
            'scriptUnitsJson' => json_encode([['type' => 'speech', 'text' => '猫']]),
            'audioUrl' => '/uploads/daily-audio-practice/drill.mp3',
            'timingData' => json_encode([['unitIndex' => 0, 'startTime' => 0, 'endTime' => 1]]),
            'approxDurationSeconds' => 1,
            'generationMetadataJson' => json_encode(['provider' => 'test']),
            'errorMessage' => null,
            'createdAt' => '2026-07-14 10:00:00.561',
            'updatedAt' => '2026-07-14 10:00:00.679',
        ]);
    }

    private function seedImport(Connection $source, string $now): void
    {
        $source->table('study_import_jobs')->insert([
            'id' => self::SOURCE_IMPORT_ID,
            'userId' => self::SOURCE_USER_ID,
            'status' => 'completed',
            'sourceType' => 'anki_colpkg',
            'sourceFilename' => 'deck.colpkg',
            'sourceObjectPath' => 'imports/deck.colpkg',
            'sourceContentType' => 'application/zip',
            'sourceSizeBytes' => 123,
            'deckName' => '日本語',
            'previewJson' => json_encode(['cards' => 1]),
            'summaryJson' => json_encode(['imported' => 1]),
            'errorMessage' => null,
            'startedAt' => $now,
            'uploadedAt' => $now,
            'uploadExpiresAt' => $now,
            'completedAt' => '2026-07-14 10:00:00.108',
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);
    }

    private function seedMedia(Connection $source, string $now): void
    {
        foreach (['source-media-1', 'source-media-duplicate'] as $mediaId) {
            $source->table('study_media')->insert([
                'id' => $mediaId,
                'userId' => self::SOURCE_USER_ID,
                'importJobId' => self::SOURCE_IMPORT_ID,
                'sourceKind' => 'anki_import',
                'sourceMediaKey' => $mediaId,
                'sourceFilename' => 'neko.mp3',
                'normalizedFilename' => 'neko.mp3',
                'mediaKind' => 'audio',
                'contentType' => 'audio/mpeg',
                'storagePath' => 'study-media/'.self::SOURCE_USER_ID.'/neko.mp3',
                'publicUrl' => null,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
        }
    }

    private function seedNote(Connection $source): void
    {
        $source->table('study_notes')->insert([
            'id' => self::SOURCE_NOTE_ID,
            'userId' => self::SOURCE_USER_ID,
            'sourceKind' => 'anki_import',
            'sourceNoteId' => 321,
            'sourceGuid' => 'source-note-guid',
            'sourceNotetypeId' => 654,
            'sourceNotetypeName' => 'Japanese - Vocab',
            'rawFieldsJson' => json_encode([
                'Expression' => '猫',
                'Meaning' => 'cat',
            ]),
            'canonicalJson' => json_encode([
                'expression' => '猫',
                'meaning' => 'cat',
            ]),
            'createdAt' => '2026-06-14 22:18:00.956',
            'updatedAt' => '2026-07-13 09:08:07.654',
        ]);
    }

    private function seedCard(Connection $source, string $now): void
    {
        $source->table('study_cards')->insert([
            'id' => self::SOURCE_CARD_ID,
            'userId' => self::SOURCE_USER_ID,
            'noteId' => self::SOURCE_NOTE_ID,
            'importJobId' => self::SOURCE_IMPORT_ID,
            'sourceKind' => 'anki_import',
            'sourceCardId' => 123,
            'sourceDeckId' => 456,
            'sourceDeckName' => '日本語',
            'sourceTemplateOrd' => 0,
            'sourceTemplateName' => 'Card 1',
            'sourceQueue' => null,
            'sourceCardType' => null,
            'sourceDue' => null,
            'sourceInterval' => null,
            'sourceFactor' => null,
            'sourceReps' => null,
            'sourceLapses' => null,
            'sourceLeft' => null,
            'sourceOriginalDue' => null,
            'sourceOriginalDeckId' => null,
            'sourceFsrsJson' => null,
            'cardType' => 'recognition',
            'queueState' => 'review',
            'dueAt' => '2026-07-14 10:00:00.844',
            'lastReviewedAt' => $now,
            'promptJson' => json_encode([
                'cueHtml' => '<strong>猫</strong>',
                'cueText' => '猫',
                'cueReading' => '猫[ねこ]',
            ]),
            'answerJson' => json_encode([
                'meaning' => 'cat',
                'answerAudio' => ['text' => 'nested media metadata must not become card text'],
            ]),
            'schedulerStateJson' => json_encode(['state' => 'review']),
            'answerAudioSource' => 'generated',
            'promptAudioMediaId' => 'source-media-1',
            'answerAudioMediaId' => 'source-media-duplicate',
            'imageMediaId' => null,
            'createdAt' => $now,
            'updatedAt' => $now,
            'searchText' => '猫 cat',
            'introducedAt' => $now,
            'newQueuePosition' => null,
            'failedAt' => null,
            'variantGroupId' => null,
            'variantKind' => null,
            'variantSentenceId' => null,
            'variantStage' => null,
            'variantStatus' => null,
            'variantUnlockedAt' => null,
        ]);
    }

    private function seedReview(Connection $source, string $now): void
    {
        $source->table('study_review_logs')->insert([
            'id' => 'source-review-1',
            'userId' => self::SOURCE_USER_ID,
            'cardId' => self::SOURCE_CARD_ID,
            'importJobId' => self::SOURCE_IMPORT_ID,
            'source' => 'convolab',
            'sourceReviewId' => 789,
            'reviewedAt' => $now,
            'rating' => 3,
            'durationMs' => 1000,
            'sourceEase' => 3,
            'sourceInterval' => 10,
            'sourceLastInterval' => 5,
            'sourceFactor' => 2500,
            'sourceTimeMs' => 1000,
            'sourceReviewType' => 1,
            'stateBeforeJson' => json_encode(['before' => true]),
            'stateAfterJson' => json_encode(['after' => true]),
            'rawPayloadJson' => json_encode([
                'grade' => 'good',
                'beforeQueueState' => 'review',
                'beforeDueAt' => '2026-07-14T09:00:00.000Z',
                'beforeIntroducedAt' => null,
                'beforeLastReviewedAt' => '2026-07-13T10:00:00.000Z',
            ]),
            'createdAt' => $now,
        ]);
    }
}
