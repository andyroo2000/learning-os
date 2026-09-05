<?php

namespace Tests\Feature\Rehearsal;

use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Rehearsal\BuildsConvoLabDailyAudioImportFixtures;
use Tests\TestCase;

class ConvoLabDailyAudioImportSafetyTest extends TestCase
{
    use BuildsConvoLabDailyAudioImportFixtures;
    use RefreshDatabase;

    public function test_rejects_missing_source_bytes_without_changing_the_target(): void
    {
        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->expectsOutputToContain(
                'Convo Lab Daily Audio bytes are missing for track ['.self::TRACK_ID.'] at ['.
                self::SOURCE_OBJECT_PATH.'].',
            )
            ->assertExitCode(1);

        $this->assertLegacyTargetState();
    }

    public function test_rejects_a_different_existing_destination_file(): void
    {
        $this->putSourceFile(self::SOURCE_OBJECT_PATH, 'source-audio');
        $path = DailyAudioPracticeGeneration::storagePath(self::PRACTICE_ID, self::TRACK_ID);
        Storage::disk('daily-audio-import-test')->put($path, 'different-audio');

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->expectsOutputToContain(
                "Learning OS Daily Audio file [{$path}] has different bytes.",
            )
            ->assertExitCode(1);

        $this->assertSame(
            'different-audio',
            Storage::disk('daily-audio-import-test')->get($path),
        );
        $this->assertSame(
            'https://storage.googleapis.com/convolab-storage/legacy.mp3',
            DailyAudioPracticeTrack::query()->sole()->audio_url,
        );
    }

    public function test_rejects_a_source_symlink_that_escapes_the_export_root(): void
    {
        $this->putSourceFile(self::SOURCE_OBJECT_PATH, 'placeholder');
        $insidePath = $this->sourceMediaRoot.DIRECTORY_SEPARATOR.
            str_replace('/', DIRECTORY_SEPARATOR, self::SOURCE_OBJECT_PATH);
        $outsidePath = tempnam(storage_path('framework/testing'), 'daily-audio-outside-');
        $this->assertIsString($outsidePath);
        file_put_contents($outsidePath, 'outside-audio');
        unlink($insidePath);
        $this->assertTrue(symlink($outsidePath, $insidePath));

        try {
            $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
                ->expectsOutputToContain(
                    'Convo Lab Daily Audio bytes are missing for track ['.self::TRACK_ID.'] at ['.
                    self::SOURCE_OBJECT_PATH.'].',
                )
                ->assertExitCode(1);
        } finally {
            unlink($outsidePath);
        }

        $this->assertLegacyTargetState();
    }

    public function test_rejects_unsupported_or_unsafe_source_urls(): void
    {
        $source = DB::connection('convolab_daily_audio_test_source');

        foreach ([
            'http://storage.googleapis.com/convolab-storage/'.self::SOURCE_OBJECT_PATH,
            'https://storage.googleapis.com/other-bucket/'.self::SOURCE_OBJECT_PATH,
            'https://storage.googleapis.com/convolab-storage/daily-audio-practice/'.
                self::PRACTICE_ID.'/../secret.mp3',
            'https://storage.googleapis.com/convolab-storage/daily-audio-practice/'.
                self::PRACTICE_ID.'/audio.mp3?signature=unexpected',
        ] as $audioUrl) {
            $source->table('daily_audio_practice_tracks')->update(['audioUrl' => $audioUrl]);

            $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
                ->assertExitCode(1);
        }

        $this->assertLegacyTargetState();
    }

    public function test_rejects_inconsistent_ready_media_state(): void
    {
        $source = DB::connection('convolab_daily_audio_test_source');
        $source->table('daily_audio_practice_tracks')->update(['audioUrl' => null]);

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->expectsOutputToContain(
                'Convo Lab Daily Audio track ['.self::TRACK_ID.
                '] has inconsistent ready media state.',
            )
            ->assertExitCode(1);

        $source->table('daily_audio_practice_tracks')->update([
            'status' => 'error',
            'audioUrl' => 'https://storage.googleapis.com/convolab-storage/'.self::SOURCE_OBJECT_PATH,
        ]);

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->expectsOutputToContain(
                'Convo Lab Daily Audio track ['.self::TRACK_ID.
                '] has inconsistent ready media state.',
            )
            ->assertExitCode(1);
    }

    public function test_rejects_dangling_source_and_unmatched_legacy_target_tracks(): void
    {
        $source = DB::connection('convolab_daily_audio_test_source');
        $source->table('daily_audio_practices')->delete();

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->expectsOutputToContain(
                'Convo Lab Daily Audio track ['.self::TRACK_ID.'] references a missing practice.',
            )
            ->assertExitCode(1);

        $source->table('daily_audio_practices')->insert([
            'id' => self::PRACTICE_ID,
            'createdAt' => self::PRACTICE_CREATED_AT,
            'updatedAt' => self::PRACTICE_UPDATED_AT,
        ]);
        $source->table('daily_audio_practice_tracks')->delete();

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->expectsOutputToContain(
                'Learning OS legacy Daily Audio track ['.self::TRACK_ID.
                '] has no matching Convo Lab source media.',
            )
            ->assertExitCode(1);
    }

    public function test_rejects_a_missing_or_mismatched_target_track(): void
    {
        $this->putSourceFile(self::SOURCE_OBJECT_PATH, 'verified-audio');
        $target = DailyAudioPracticeTrack::query()->sole();
        $target->delete();

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->expectsOutputToContain(
                'Learning OS has no Daily Audio track matching Convo Lab track ['.self::TRACK_ID.'].',
            )
            ->assertExitCode(1);

        DailyAudioPracticeTrack::factory()->create([
            'id' => self::TRACK_ID,
            'practice_id' => self::PRACTICE_ID,
            'mode' => 'dialogue',
            'status' => 'ready',
            'audio_url' => 'https://storage.googleapis.com/convolab-storage/legacy.mp3',
        ]);

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->expectsOutputToContain(
                'Learning OS Daily Audio track ['.self::TRACK_ID.
                '] does not match its ready Convo Lab source.',
            )
            ->assertExitCode(1);
    }

    public function test_removes_a_new_file_when_the_database_transaction_fails(): void
    {
        $this->putSourceFile(self::SOURCE_OBJECT_PATH, 'verified-audio');
        $forceFailure = true;
        DB::listen(function (QueryExecuted $query) use (&$forceFailure): void {
            $sql = strtolower($query->sql);

            if (! $forceFailure || ! str_contains($sql, 'update')) {
                return;
            }

            if (! str_contains($sql, 'daily_audio_practice_tracks')) {
                return;
            }

            $forceFailure = false;

            throw new \RuntimeException('forced Daily Audio update failure');
        });

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->expectsOutputToContain('forced Daily Audio update failure')
            ->assertExitCode(1);

        $path = DailyAudioPracticeGeneration::storagePath(self::PRACTICE_ID, self::TRACK_ID);
        Storage::disk('daily-audio-import-test')->assertMissing($path);
        $this->assertLegacyTargetState();
    }

    public function test_rejects_a_concurrent_import_before_touching_storage_or_data(): void
    {
        $this->putSourceFile(self::SOURCE_OBJECT_PATH, 'verified-audio');
        $lock = Cache::store('database')->lock(
            'migration:import-convolab-daily-audio:'.DB::connection()->getDatabaseName(),
            30,
        );
        $this->assertTrue($lock->get());

        try {
            $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
                ->expectsOutputToContain(
                    'Another Convo Lab Daily Audio import is already running for this target database.',
                )
                ->assertExitCode(1);
        } finally {
            $lock->release();
        }

        $this->assertLegacyTargetState();
    }

    public function test_rejects_a_source_connection_that_resolves_to_the_target_database(): void
    {
        $this->artisan('migration:import-convolab-daily-audio', [
            ...$this->commandOptions(),
            '--source-connection' => DB::getDefaultConnection(),
        ])
            ->expectsOutputToContain(
                'Source and target databases resolve to the same database. Use a separate restored source copy.',
            )
            ->assertExitCode(1);

        $this->assertLegacyTargetState();
    }

    public function test_production_requires_override_and_database_specific_confirmation(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->putSourceFile(self::SOURCE_OBJECT_PATH, 'verified-audio');

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->expectsOutputToContain(
                'This command must not run in production without --allow-production.',
            )
            ->assertExitCode(1);

        $this->artisan('migration:import-convolab-daily-audio', [
            ...$this->commandOptions(),
            '--allow-production' => true,
        ])
            ->expectsOutputToContain(
                'Production Daily Audio import requires --production-confirmation="IMPORT DAILY AUDIO INTO '.
                DB::connection()->getDatabaseName().'".',
            )
            ->assertExitCode(1);

        $this->artisan('migration:import-convolab-daily-audio', [
            ...$this->commandOptions(),
            '--allow-production' => true,
            '--production-confirmation' => 'IMPORT DAILY AUDIO INTO '.
                DB::connection()->getDatabaseName(),
        ])->assertExitCode(0);
    }
}
