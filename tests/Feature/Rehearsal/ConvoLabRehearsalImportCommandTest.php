<?php

namespace Tests\Feature\Rehearsal;

use App\Domain\Courses\Models\Course;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConvoLabRehearsalImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_IMPORT_ID = '98f42a62-8303-410e-ad4d-5a69c55911bb';

    private string $sourceDatabase;

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

    public function test_imports_convolab_source_data_into_the_rehearsal_schema(): void
    {
        $this->seedConvoLabSourceData();

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Imported 1 users.')
            ->expectsOutputToContain('Imported 1 decks.')
            ->expectsOutputToContain('Imported 1 study settings rows.')
            ->expectsOutputToContain('Imported 1 study import jobs.')
            ->expectsOutputToContain('Imported 1 media assets (1 duplicate source paths reused).')
            ->expectsOutputToContain('Imported 1 cards.')
            ->expectsOutputToContain('Imported 1 card media links.')
            ->expectsOutputToContain('Imported 1 review events.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
        ]);
        $this->assertDatabaseHas('decks', [
            'name' => '日本語',
            'is_manual_study_deck' => false,
        ]);
        $this->assertDatabaseHas('cards', [
            'card_type' => 'recognition',
            'study_status' => 'review',
            'front_text' => '猫',
            'back_text' => 'cat',
        ]);
        $this->assertDatabaseHas('study_import_jobs', [
            'convolab_id' => self::SOURCE_IMPORT_ID,
        ]);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseHas('media_assets', [
            'disk' => 'media',
            'path' => 'study-media/source-user-1/neko.mp3',
        ]);
        $this->assertDatabaseCount('card_media', 1);
        $this->assertDatabaseHas('card_review_events', [
            'rating' => 'good',
        ]);
        $this->assertSame([
            'study_status' => 'review',
            'new_queue_position' => null,
            'scheduler_state' => ['before' => true],
            'due_at' => '2026-07-14T09:00:00.000Z',
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => '2026-07-13T10:00:00.000Z',
        ], CardReviewEvent::query()->sole()->card_state_before);

        $this->artisan('rehearsal:smoke', [
            '--user-email' => 'ada@example.com',
        ])->assertExitCode(0);
    }

    public function test_does_not_invent_an_undo_snapshot_when_the_prior_new_queue_position_is_unknown(): void
    {
        $this->seedConvoLabSourceData();
        $source = DB::connection('convolab_test_source');
        $payload = json_decode($source->table('study_review_logs')->value('rawPayloadJson'), true);
        $payload['beforeQueueState'] = 'new';
        $payload['beforeDueAt'] = null;
        $payload['beforeIntroducedAt'] = null;
        $payload['beforeLastReviewedAt'] = null;
        $source->table('study_review_logs')->update(['rawPayloadJson' => json_encode($payload)]);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])->assertExitCode(0);

        $this->assertNull(CardReviewEvent::query()->sole()->card_state_before);
    }

    public function test_production_truncate_requires_a_database_specific_confirmation(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->seedConvoLabSourceData();
        $targetDatabase = DB::connection()->getDatabaseName();

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
            '--allow-production' => true,
        ])
            ->expectsOutputToContain(
                "Production truncation requires --production-truncate-confirmation=\"TRUNCATE {$targetDatabase}\".",
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_production_truncate_accepts_the_exact_target_database_confirmation(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->seedConvoLabSourceData();
        $targetDatabase = DB::connection()->getDatabaseName();

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
            '--skip-media' => true,
            '--allow-production' => true,
            '--production-truncate-confirmation' => "TRUNCATE {$targetDatabase}",
        ])->assertExitCode(0);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_production_import_rejects_metadata_only_media(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->seedConvoLabSourceData();
        $targetDatabase = DB::connection()->getDatabaseName();

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
            '--allow-production' => true,
            '--production-truncate-confirmation' => "TRUNCATE {$targetDatabase}",
        ])
            ->expectsOutputToContain(
                'Production import requires --skip-media because Convo Lab does not store media byte sizes.',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_refuses_to_import_into_a_non_empty_target_without_truncate(): void
    {
        $this->seedConvoLabSourceData();
        $existingUser = User::factory()->create();

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
        ])
            ->expectsOutputToContain('Learning OS target table [users] is not empty.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('users', ['id' => $existingUser->id]);
        $this->assertDatabaseCount('cards', 0);
    }

    public function test_rejects_duplicate_source_emails_instead_of_merging_users(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')->table('User')->insert([
            'id' => 'source-user-duplicate-email',
            'email' => 'ADA@example.com',
            'password' => null,
            'name' => 'Other Ada',
            'displayName' => null,
            'emailVerifiedAt' => null,
            'createdAt' => '2026-07-14 10:00:00',
            'updatedAt' => '2026-07-14 10:00:00',
        ]);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Multiple Convo Lab users share email [ADA@example.com].')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_rejects_an_unsupported_source_password_hash(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')->table('User')->update(['password' => 'not-a-password-hash']);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Convo Lab user [source-user-1] has an unsupported password hash.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_normalizes_node_bcrypt_passwords_for_php_authentication(): void
    {
        $this->seedConvoLabSourceData();
        $nodeBcryptHash = '$2b$'.substr(Hash::make('correct horse battery staple'), 4);
        DB::connection('convolab_test_source')->table('User')->update(['password' => $nodeBcryptHash]);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])->assertExitCode(0);

        $importedHash = User::query()->sole()->password;
        $this->assertStringStartsWith('$2y$', $importedHash);
        $this->assertTrue(Hash::check('correct horse battery staple', $importedHash));
    }

    public function test_rejects_card_media_links_across_user_ownership_boundaries(): void
    {
        $this->seedConvoLabSourceData();
        $source = DB::connection('convolab_test_source');
        $source->table('User')->insert([
            'id' => 'source-user-2',
            'email' => 'grace@example.com',
            'password' => null,
            'name' => 'Grace',
            'displayName' => null,
            'emailVerifiedAt' => null,
            'createdAt' => '2026-07-14 10:00:00',
            'updatedAt' => '2026-07-14 10:00:00',
        ]);
        $source->table('study_media')->update(['userId' => 'source-user-2']);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain(
                'Card [source-card-1] references media [source-media-1] owned by another user.',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_truncate_and_import_roll_back_together_when_a_late_mapping_fails(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')->table('study_review_logs')->update(['rating' => 9]);
        $existingUser = User::factory()->create();

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Unsupported Convo Lab review rating [9].')
            ->assertExitCode(1);

        $this->assertDatabaseHas('users', ['id' => $existingUser->id]);
        $this->assertDatabaseCount('cards', 0);
    }

    public function test_truncate_explicitly_clears_the_full_user_data_boundary(): void
    {
        $this->seedConvoLabSourceData();
        $existingUser = User::factory()->create();
        Course::factory()->for($existingUser)->create();
        StudyCardDraft::factory()->for($existingUser)->create();
        SyncFeedEntry::factory()->for($existingUser)->create();

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('courses', 0);
        $this->assertDatabaseCount('study_card_drafts', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_deduplicates_deck_names_after_legacy_values_are_defaulted(): void
    {
        $this->seedConvoLabSourceData();
        $source = DB::connection('convolab_test_source');
        $template = (array) $source->table('study_cards')->where('id', 'source-card-1')->first();

        foreach ([['source-card-2', 124, null], ['source-card-3', 125, '   ']] as [$id, $sourceCardId, $deckName]) {
            $source->table('study_cards')->insert([
                ...$template,
                'id' => $id,
                'noteId' => 'note-'.$id,
                'sourceCardId' => $sourceCardId,
                'sourceDeckName' => $deckName,
                'promptAudioMediaId' => null,
                'answerAudioMediaId' => null,
            ]);
        }

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('decks', 2);
        $this->assertDatabaseCount('cards', 3);
        $this->assertDatabaseHas('decks', ['name' => 'Convo Lab Study Cards']);
    }

    public function test_rejects_a_dangling_import_job_reference_instead_of_discarding_it(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')
            ->table('study_cards')
            ->update(['importJobId' => 'missing-import-job']);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Missing imported study job mapping for [missing-import-job].')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('cards', 0);
    }

    public function test_rejects_a_non_uuid_convolab_import_job_identifier(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')
            ->table('study_import_jobs')
            ->update(['id' => 'not-a-uuid']);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Convo Lab import job [not-a-uuid] does not have a valid UUID.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('study_import_jobs', 0);
    }

    public function test_rejects_a_review_with_a_missing_card_instead_of_discarding_it(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')
            ->table('study_review_logs')
            ->update(['cardId' => 'missing-card']);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Missing imported card mapping for review [source-review-1].')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('card_review_events', 0);
    }

    public function test_rejects_shared_media_paths_across_users(): void
    {
        $this->seedConvoLabSourceData();
        $source = DB::connection('convolab_test_source');
        $source->table('User')->insert([
            'id' => 'source-user-2',
            'email' => 'grace@example.com',
            'password' => null,
            'name' => 'Grace',
            'displayName' => null,
            'emailVerifiedAt' => null,
            'createdAt' => '2026-07-14 10:00:00',
            'updatedAt' => '2026-07-14 10:00:00',
        ]);
        $media = (array) $source->table('study_media')->where('id', 'source-media-1')->first();
        $source->table('study_media')->insert([
            ...$media,
            'id' => 'source-media-other-user',
            'userId' => 'source-user-2',
            'importJobId' => null,
        ]);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Media path [study-media/source-user-1/neko.mp3] is shared by multiple Convo Lab users.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_rejects_a_source_connection_name_that_would_replace_the_target_connection(): void
    {
        $defaultConnection = DB::getDefaultConnection();

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => $defaultConnection,
            '--source-database' => 'learning_os_convolab_source',
        ])
            ->expectsOutputToContain('Source connection name must differ from the target connection name.')
            ->assertExitCode(1);
    }

    private function createConvoLabSourceSchema(): void
    {
        $schema = Schema::connection('convolab_test_source');

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

    private function seedConvoLabSourceData(): void
    {
        $source = DB::connection('convolab_test_source');
        $now = '2026-07-14 10:00:00';

        $source->table('User')->insert([
            'id' => 'source-user-1',
            'email' => 'ada@example.com',
            'password' => null,
            'name' => 'Ada',
            'displayName' => 'Ada Lovelace',
            'emailVerifiedAt' => $now,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);

        $source->table('study_settings')->insert([
            'userId' => 'source-user-1',
            'newCardsPerDay' => 12,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);

        $source->table('study_import_jobs')->insert([
            'id' => self::SOURCE_IMPORT_ID,
            'userId' => 'source-user-1',
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
            'completedAt' => $now,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);

        foreach (['source-media-1', 'source-media-duplicate'] as $mediaId) {
            $source->table('study_media')->insert([
                'id' => $mediaId,
                'userId' => 'source-user-1',
                'importJobId' => self::SOURCE_IMPORT_ID,
                'sourceKind' => 'anki_import',
                'sourceMediaKey' => $mediaId,
                'sourceFilename' => 'neko.mp3',
                'normalizedFilename' => 'neko.mp3',
                'mediaKind' => 'audio',
                'contentType' => 'audio/mpeg',
                'storagePath' => 'study-media/source-user-1/neko.mp3',
                'publicUrl' => null,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
        }

        $source->table('study_cards')->insert([
            'id' => 'source-card-1',
            'userId' => 'source-user-1',
            'noteId' => 'source-note-1',
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
            'dueAt' => $now,
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

        $source->table('study_review_logs')->insert([
            'id' => 'source-review-1',
            'userId' => 'source-user-1',
            'cardId' => 'source-card-1',
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
