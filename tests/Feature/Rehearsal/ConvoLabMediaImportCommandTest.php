<?php

namespace Tests\Feature\Rehearsal;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConvoLabMediaImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_CARD_ID = 'c358732a-2cd0-4b18-9cce-c474297863f9';

    private const SOURCE_IMPORT_ID = '98f42a62-8303-410e-ad4d-5a69c55911bb';

    private string $sourceDatabase;

    private string $sourceMediaRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MediaAsset::DISK_MEDIA);
        $this->sourceDatabase = storage_path('framework/testing/convolab-media-source-'.uniqid().'.sqlite');
        $this->sourceMediaRoot = storage_path('framework/testing/convolab-media-files-'.uniqid());
        touch($this->sourceDatabase);
        mkdir($this->sourceMediaRoot, 0777, true);

        config([
            'database.connections.convolab_media_test_source' => [
                'driver' => 'sqlite',
                'database' => $this->sourceDatabase,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('convolab_media_test_source');
        $this->createSourceSchema();
        $this->seedTarget();
        $this->seedSource();
    }

    protected function tearDown(): void
    {
        DB::purge('convolab_media_test_source');

        if (isset($this->sourceDatabase) && is_file($this->sourceDatabase)) {
            unlink($this->sourceDatabase);
        }

        if (isset($this->sourceMediaRoot) && is_dir($this->sourceMediaRoot)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->sourceMediaRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->sourceMediaRoot);
        }

        parent::tearDown();
    }

    public function test_imports_verified_media_into_an_existing_learning_os_database(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->expectsOutputToContain('Verified 1 unique media files and 1 card media links.')
            ->expectsOutputToContain('Convo Lab media import completed: 1 media assets, 1 new card links.')
            ->assertExitCode(0);

        $media = MediaAsset::query()->sole();
        $this->assertSame('study-media/source-user/neko.mp3', $media->path);
        $this->assertSame(strlen('verified-neko-bytes'), $media->size_bytes);
        $this->assertSame(hash('sha256', 'verified-neko-bytes'), $media->checksum_sha256);
        $this->assertSame('audio/mpeg', $media->mime_type);
        $this->assertSame('anki_import', $media->source_kind);
        $this->assertSame('0', $media->source_media_ref);
        $this->assertSame('neko.mp3', $media->source_filename);
        $this->assertSame(StudyImportJob::query()->sole()->id, $media->import_job_id);
        Storage::disk(MediaAsset::DISK_MEDIA)
            ->assertExists('study-media/source-user/neko.mp3');
        $this->assertSame(
            'verified-neko-bytes',
            Storage::disk(MediaAsset::DISK_MEDIA)->get('study-media/source-user/neko.mp3'),
        );
        $this->assertDatabaseHas('card_media', [
            'card_id' => Card::query()->sole()->id,
            'media_asset_id' => $media->id,
        ]);
        $this->assertImportedSyncFeed($media);
    }

    public function test_retry_reuses_verified_media_and_card_link(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'same-bytes');

        $this->artisan('migration:import-convolab-media', $this->commandOptions())->assertExitCode(0);
        $mediaId = MediaAsset::query()->sole()->id;

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->expectsOutputToContain('Convo Lab media import completed: 1 media assets, 0 new card links.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('card_media', 1);
        $this->assertDatabaseCount('sync_feed_entries', 2);
        $this->assertSame($mediaId, MediaAsset::query()->sole()->id);
    }

    public function test_backfills_missing_sync_entries_for_existing_media_and_card_links(): void
    {
        $contents = 'already-verified-bytes';
        $path = 'study-media/source-user/neko.mp3';
        $this->putSourceFile($path, $contents);
        $card = Card::query()->sole();
        $media = MediaAsset::query()->forceCreate([
            'user_id' => User::query()->sole()->id,
            'import_job_id' => StudyImportJob::query()->sole()->id,
            'disk' => MediaAsset::DISK_MEDIA,
            'path' => $path,
            'mime_type' => 'audio/mpeg',
            'size_bytes' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
            'original_filename' => 'neko.mp3',
            'source_kind' => 'anki_import',
            'source_media_ref' => '0',
            'source_filename' => 'neko.mp3',
        ]);
        DB::table('card_media')->insert([
            'card_id' => $card->id,
            'media_asset_id' => $media->id,
            'created_at' => '2026-07-19 10:00:00',
            'updated_at' => '2026-07-19 10:00:00',
        ]);

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->expectsOutputToContain('Convo Lab media import completed: 1 media assets, 0 new card links.')
            ->assertExitCode(0);
        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->assertExitCode(0);

        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('card_media', 1);
        $this->assertDatabaseCount('sync_feed_entries', 2);
        $this->assertDatabaseHas('sync_feed_entries', [
            'resource_type' => MediaAssetSyncPayload::RESOURCE_TYPE,
            'resource_id' => $media->id,
            'operation' => SyncFeedOperation::Create->value,
        ]);
        $this->assertDatabaseHas('sync_feed_entries', [
            'resource_type' => CardMediaSyncPayload::RESOURCE_TYPE,
            'resource_id' => CardMediaSyncPayload::resourceId($card->id, $media->id),
            'operation' => SyncFeedOperation::Create->value,
        ]);
    }

    public function test_rejects_a_concurrent_import_before_touching_storage_or_data(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        $lock = Cache::store('database')->lock(
            'migration:import-convolab-media:'.DB::connection()->getDatabaseName(),
            30,
        );
        $this->assertTrue($lock->get());

        try {
            $this->artisan('migration:import-convolab-media', $this->commandOptions())
                ->expectsOutputToContain(
                    'Another Convo Lab media import is already running for this target database.',
                )
                ->assertExitCode(1);
        } finally {
            $lock->release();
        }

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        Storage::disk(MediaAsset::DISK_MEDIA)->assertMissing('study-media/source-user/neko.mp3');
    }

    public function test_upgrades_a_metadata_only_rehearsal_row(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'real-bytes');
        $user = User::query()->sole();
        $existing = MediaAsset::query()->forceCreate([
            'user_id' => $user->id,
            'disk' => MediaAsset::DISK_MEDIA,
            'path' => 'study-media/source-user/neko.mp3',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 0,
            'checksum_sha256' => null,
            'original_filename' => 'neko.mp3',
        ]);

        $this->artisan('migration:import-convolab-media', $this->commandOptions())->assertExitCode(0);

        $this->assertSame($existing->id, MediaAsset::query()->sole()->id);
        $this->assertSame(strlen('real-bytes'), $existing->fresh()->size_bytes);
        $this->assertSame(hash('sha256', 'real-bytes'), $existing->fresh()->checksum_sha256);
        $this->assertSame('audio/mpeg', $existing->fresh()->mime_type);
    }

    public function test_preflight_rejects_missing_source_bytes_without_writing_data(): void
    {
        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->expectsOutputToContain(
                'Convo Lab media bytes are missing for [source-media-1] at [study-media/source-user/neko.mp3].',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
        Storage::disk(MediaAsset::DISK_MEDIA)->assertMissing('study-media/source-user/neko.mp3');
    }

    public function test_preflight_rejects_a_different_existing_destination_file(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'source-bytes');
        Storage::disk(MediaAsset::DISK_MEDIA)
            ->put('study-media/source-user/neko.mp3', 'different-target-bytes');

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->expectsOutputToContain(
                'Learning OS media file [study-media/source-user/neko.mp3] has different bytes.',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame(
            'different-target-bytes',
            Storage::disk(MediaAsset::DISK_MEDIA)->get('study-media/source-user/neko.mp3'),
        );
    }

    public function test_preflight_rejects_cross_user_card_media_ownership(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        $source = DB::connection('convolab_media_test_source');
        $source->table('User')->insert([
            'id' => 'source-user-2',
            'email' => 'grace@example.com',
        ]);
        User::factory()->create(['email' => 'grace@example.com']);
        $source->table('study_media')->update(['userId' => 'source-user-2']);

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->expectsOutputToContain(
                'Card ['.self::SOURCE_CARD_ID.'] references media [source-media-1] owned by another user.',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_ignores_unreferenced_users_and_cards_that_were_not_imported(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        $source = DB::connection('convolab_media_test_source');
        $source->table('User')->insert([
            'id' => 'unreferenced-user',
            'email' => 'not-imported@example.com',
        ]);
        $source->table('study_cards')->insert([
            'id' => '0cbaf65b-239d-4080-a3fc-7a8e411ce90e',
            'userId' => 'unreferenced-user',
            'promptAudioMediaId' => null,
            'answerAudioMediaId' => null,
            'imageMediaId' => null,
            'createdAt' => '2026-07-19 10:00:00',
            'updatedAt' => '2026-07-19 10:00:00',
        ]);

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->assertExitCode(0);

        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('card_media', 1);
    }

    public function test_imports_unlinked_source_media_without_inventing_a_card_link(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        DB::connection('convolab_media_test_source')
            ->table('study_cards')
            ->update([
                'promptAudioMediaId' => null,
                'answerAudioMediaId' => null,
                'imageMediaId' => null,
            ]);

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->expectsOutputToContain('Verified 1 unique media files and 0 card media links.')
            ->expectsOutputToContain('Convo Lab media import completed: 1 media assets, 0 new card links.')
            ->assertExitCode(0);

        $media = MediaAsset::query()->sole();
        $this->assertDatabaseCount('card_media', 0);
        $this->assertDatabaseCount('sync_feed_entries', 1);
        $this->assertDatabaseHas('sync_feed_entries', [
            'user_id' => $media->user_id,
            'domain' => MediaAssetSyncPayload::DOMAIN,
            'resource_type' => MediaAssetSyncPayload::RESOURCE_TYPE,
            'resource_id' => $media->id,
            'operation' => SyncFeedOperation::Create->value,
        ]);
    }

    public function test_rejects_media_for_a_soft_deleted_target_card(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        Card::query()->sole()->delete();

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->expectsOutputToContain(
                'Learning OS has no card matching Convo Lab card ['.self::SOURCE_CARD_ID.'].',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
        Storage::disk(MediaAsset::DISK_MEDIA)->assertMissing('study-media/source-user/neko.mp3');
    }

    public function test_rejects_media_for_a_card_in_a_soft_deleted_target_deck(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        Card::query()->sole()->deck()->sole()->delete();

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->expectsOutputToContain(
                'Learning OS has no card matching Convo Lab card ['.self::SOURCE_CARD_ID.'].',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
        Storage::disk(MediaAsset::DISK_MEDIA)->assertMissing('study-media/source-user/neko.mp3');
    }

    public function test_rejects_missing_import_job_provenance(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        StudyImportJob::query()->delete();

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->expectsOutputToContain(
                'Learning OS has no import job matching Convo Lab import job ['.
                self::SOURCE_IMPORT_ID.'].',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_removes_new_files_when_the_database_transaction_fails(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        $forceFailure = true;
        DB::listen(function (QueryExecuted $query) use (&$forceFailure): void {
            $sql = strtolower($query->sql);

            if ($forceFailure && str_contains($sql, 'insert') && str_contains($sql, 'card_media')) {
                $forceFailure = false;

                throw new \RuntimeException('forced card media failure');
            }
        });

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->expectsOutputToContain('forced card media failure')
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        Storage::disk(MediaAsset::DISK_MEDIA)->assertMissing('study-media/source-user/neko.mp3');
    }

    public function test_rejects_unsafe_source_paths(): void
    {
        DB::connection('convolab_media_test_source')
            ->table('study_media')
            ->update(['storagePath' => 'study-media/../secrets.txt']);

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->expectsOutputToContain('Convo Lab media [source-media-1] has an unsafe storage path.')
            ->assertExitCode(1);
    }

    public function test_rejects_a_source_connection_that_resolves_to_the_target_database(): void
    {
        $this->artisan('migration:import-convolab-media', [
            ...$this->commandOptions(),
            '--source-connection' => DB::getDefaultConnection(),
        ])
            ->expectsOutputToContain(
                'Source and target databases resolve to the same database. Use a separate restored source copy.',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_rejects_a_source_symlink_that_escapes_the_media_root(): void
    {
        $path = 'study-media/source-user/neko.mp3';
        $this->putSourceFile($path, 'placeholder');
        $insidePath = $this->sourceMediaRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        $outsidePath = tempnam(storage_path('framework/testing'), 'convolab-media-outside-');
        $this->assertIsString($outsidePath);
        file_put_contents($outsidePath, 'outside-bytes');
        unlink($insidePath);
        $this->assertTrue(symlink($outsidePath, $insidePath));

        try {
            $this->artisan('migration:import-convolab-media', $this->commandOptions())
                ->expectsOutputToContain(
                    'Convo Lab media bytes are missing for [source-media-1] at ['.$path.'].',
                )
                ->assertExitCode(1);
        } finally {
            unlink($outsidePath);
        }

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        Storage::disk(MediaAsset::DISK_MEDIA)->assertMissing($path);
    }

    public function test_preflight_rejects_source_metadata_that_exceeds_target_column_limits(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        DB::connection('convolab_media_test_source')
            ->table('study_media')
            ->where('id', 'source-media-1')
            ->update(['sourceKind' => str_repeat('a', 65)]);

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->expectsOutputToContain(
                'Convo Lab media [source-media-1] source kind exceeds 64 characters.',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
        Storage::disk(MediaAsset::DISK_MEDIA)->assertMissing('study-media/source-user/neko.mp3');
    }

    public function test_normalizes_original_filename_while_preserving_source_filename(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        DB::connection('convolab_media_test_source')
            ->table('study_media')
            ->update(['sourceFilename' => 'audio/imported/neko.mp3']);

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->assertExitCode(0);

        $media = MediaAsset::query()->sole();
        $this->assertSame('neko.mp3', $media->original_filename);
        $this->assertSame('audio/imported/neko.mp3', $media->source_filename);
    }

    public function test_production_requires_database_specific_confirmation(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');

        $this->artisan('migration:import-convolab-media', [
            ...$this->commandOptions(),
            '--allow-production' => true,
        ])
            ->expectsOutputToContain(
                'Production media import requires --production-confirmation="IMPORT MEDIA INTO '.
                DB::connection()->getDatabaseName().'".',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_production_refuses_to_run_without_the_explicit_override(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('migration:import-convolab-media', $this->commandOptions())
            ->expectsOutputToContain(
                'This command must not run in production without --allow-production.',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_production_accepts_exact_database_confirmation(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');

        $this->artisan('migration:import-convolab-media', [
            ...$this->commandOptions(),
            '--allow-production' => true,
            '--production-confirmation' => 'IMPORT MEDIA INTO '.DB::connection()->getDatabaseName(),
        ])->assertExitCode(0);

        $this->assertDatabaseCount('media_assets', 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function commandOptions(): array
    {
        return [
            '--source-connection' => 'convolab_media_test_source',
            '--source-media-root' => $this->sourceMediaRoot,
        ];
    }

    private function createSourceSchema(): void
    {
        $schema = Schema::connection('convolab_media_test_source');
        $schema->create('User', function ($table): void {
            $table->text('id')->primary();
            $table->text('email');
        });
        $schema->create('study_import_jobs', function ($table): void {
            $table->text('id')->primary();
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
            $table->text('promptAudioMediaId')->nullable();
            $table->text('answerAudioMediaId')->nullable();
            $table->text('imageMediaId')->nullable();
            $table->timestamp('createdAt');
            $table->timestamp('updatedAt');
        });
    }

    private function seedTarget(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        Card::factory()->for($this->deckFor($user))->create([
            'convolab_id' => self::SOURCE_CARD_ID,
        ]);
        StudyImportJob::factory()->for($user)->create([
            'convolab_id' => self::SOURCE_IMPORT_ID,
        ]);
    }

    private function seedSource(): void
    {
        $source = DB::connection('convolab_media_test_source');
        $now = '2026-07-19 10:00:00';
        $source->table('User')->insert([
            'id' => 'source-user-1',
            'email' => 'ada@example.com',
        ]);
        $source->table('study_import_jobs')->insert(['id' => self::SOURCE_IMPORT_ID]);
        foreach (['source-media-1', 'source-media-duplicate'] as $index => $id) {
            $source->table('study_media')->insert([
                'id' => $id,
                'userId' => 'source-user-1',
                'importJobId' => self::SOURCE_IMPORT_ID,
                'sourceKind' => 'anki_import',
                'sourceMediaKey' => (string) $index,
                'sourceFilename' => 'neko.mp3',
                'normalizedFilename' => 'neko.mp3',
                'mediaKind' => 'audio',
                'contentType' => 'audio/mpeg',
                'storagePath' => 'study-media/source-user/neko.mp3',
                'publicUrl' => null,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
        }
        $source->table('study_cards')->insert([
            'id' => self::SOURCE_CARD_ID,
            'userId' => 'source-user-1',
            'promptAudioMediaId' => 'source-media-1',
            'answerAudioMediaId' => 'source-media-duplicate',
            'imageMediaId' => null,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);
    }

    private function putSourceFile(string $path, string $contents): void
    {
        $absolute = $this->sourceMediaRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        $directory = dirname($absolute);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($absolute, $contents);
    }

    private function assertImportedSyncFeed(MediaAsset $media): void
    {
        $card = Card::query()->sole();
        $mediaEntry = SyncFeedEntry::query()
            ->where('resource_type', MediaAssetSyncPayload::RESOURCE_TYPE)
            ->sole();
        $cardMediaEntry = SyncFeedEntry::query()
            ->where('resource_type', CardMediaSyncPayload::RESOURCE_TYPE)
            ->sole();

        $this->assertDatabaseCount('sync_feed_entries', 2);
        $this->assertSame($media->user_id, $mediaEntry->user_id);
        $this->assertSame(MediaAssetSyncPayload::DOMAIN, $mediaEntry->domain);
        $this->assertSame($media->id, $mediaEntry->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $mediaEntry->operation);
        $this->assertSame([
            'id' => $media->id,
            'import_job_id' => StudyImportJob::query()->sole()->id,
            'source_kind' => 'anki_import',
            'source_media_ref' => '0',
            'source_filename' => 'neko.mp3',
            'url' => null,
            'content_url' => '/api/media-assets/'.$media->id.'/content',
            'mime_type' => 'audio/mpeg',
            'size_bytes' => strlen('verified-neko-bytes'),
            'checksum_sha256' => hash('sha256', 'verified-neko-bytes'),
            'original_filename' => 'neko.mp3',
            'created_at' => '2026-07-19T10:00:00.000000Z',
            'updated_at' => '2026-07-19T10:00:00.000000Z',
        ], $mediaEntry->payload);

        $this->assertSame($media->user_id, $cardMediaEntry->user_id);
        $this->assertSame(CardMediaSyncPayload::DOMAIN, $cardMediaEntry->domain);
        $this->assertSame("{$card->id}:{$media->id}", $cardMediaEntry->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $cardMediaEntry->operation);
        $this->assertSame([
            'card_id' => $card->id,
            'media_asset_id' => $media->id,
            'deck_id' => $card->deck_id,
            'course_id' => null,
            'created_at' => '2026-07-19T10:00:00.000000Z',
            'updated_at' => '2026-07-19T10:00:00.000000Z',
        ], $cardMediaEntry->payload);
    }
}
