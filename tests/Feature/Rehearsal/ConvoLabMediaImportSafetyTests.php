<?php

namespace Tests\Feature\Rehearsal;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

trait ConvoLabMediaImportSafetyTests
{
    public function test_rejects_a_concurrent_import_before_touching_storage_or_data(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        $lock = Cache::store('database')->lock(
            'migration:import-convolab-media:'.DB::connection()->getDatabaseName(),
            30,
        );
        $this->assertTrue($lock->get());

        try {
            $this->importMedia()
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

    public function test_removes_new_files_when_the_database_transaction_fails(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        $forceFailure = true;
        DB::listen(function (QueryExecuted $query) use (&$forceFailure): void {
            $this->failOnFirstCardMediaInsert($query, $forceFailure);
        });

        $this->importMedia()
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

        $this->importMedia()
            ->expectsOutputToContain('Convo Lab media [source-media-1] has an unsafe storage path.')
            ->assertExitCode(1);
    }

    public function test_rejects_a_source_connection_that_resolves_to_the_target_database(): void
    {
        $this->importMedia([
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
            $this->importMedia()
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

    public function test_production_requires_database_specific_confirmation(): void
    {
        $this->prepareProductionImport();

        $this->importMedia([
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

        $this->importMedia()
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
        $this->prepareProductionImport();

        $this->importMedia([
            '--allow-production' => true,
            '--production-confirmation' => 'IMPORT MEDIA INTO '.DB::connection()->getDatabaseName(),
        ])->assertExitCode(0);

        $this->assertDatabaseCount('media_assets', 1);
    }
}
