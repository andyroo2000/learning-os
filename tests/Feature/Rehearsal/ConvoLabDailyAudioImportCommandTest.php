<?php

namespace Tests\Feature\Rehearsal;

use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConvoLabDailyAudioImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PRACTICE_ID = '39ac4e14-b8b0-482c-8831-a3c1cb1987e9';

    private const TRACK_ID = '2e3c670d-10ed-442c-bb7b-3901e354a3d3';

    private const ERROR_TRACK_ID = '12a8c937-b990-4f50-b126-30dfe3687b85';

    private const PRACTICE_CREATED_AT = '2026-06-24 14:46:18.554';

    private const PRACTICE_UPDATED_AT = '2026-06-24 15:18:16.766';

    private const TRACK_CREATED_AT = '2026-06-24 14:46:18.561';

    private const TRACK_UPDATED_AT = '2026-06-24 15:18:16.679';

    private const SOURCE_OBJECT_PATH = 'daily-audio-practice/39ac4e14-b8b0-482c-8831-a3c1cb1987e9/'.
        'source-drill-2e3c670d-10ed-442c-bb7b-3901e354a3d3.mp3';

    private string $sourceDatabase;

    private string $sourceMediaRoot;

    private ?string $lockConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('daily-audio-import-test');
        config(['daily_audio.disk' => 'daily-audio-import-test']);
        $this->sourceDatabase = storage_path('framework/testing/convolab-daily-audio-'.uniqid().'.sqlite');
        $this->sourceMediaRoot = storage_path('framework/testing/convolab-daily-audio-files-'.uniqid());
        touch($this->sourceDatabase);
        mkdir($this->sourceMediaRoot, 0777, true);

        config([
            'database.connections.convolab_daily_audio_test_source' => [
                'driver' => 'sqlite',
                'database' => $this->sourceDatabase,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('convolab_daily_audio_test_source');
        $this->configurePostgresLockConnection();
        $this->createSourceSchema();
        $this->seedTarget();
        $this->seedSource();
    }

    protected function tearDown(): void
    {
        DB::purge('convolab_daily_audio_test_source');

        if ($this->lockConnection !== null) {
            app('cache')->forgetDriver('database');
            DB::purge($this->lockConnection);
        }

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

    public function test_imports_verified_daily_audio_and_rewrites_the_track_url(): void
    {
        $contents = 'ID3-verified-daily-audio';
        $this->putSourceFile(self::SOURCE_OBJECT_PATH, $contents);

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->expectsOutputToContain('Verified 1 historical Daily Audio tracks.')
            ->expectsOutputToContain('Convo Lab Daily Audio import completed: 1 verified tracks.')
            ->assertExitCode(0);

        $path = DailyAudioPracticeGeneration::storagePath(self::PRACTICE_ID, self::TRACK_ID);
        Storage::disk('daily-audio-import-test')->assertExists($path);
        $this->assertSame($contents, Storage::disk('daily-audio-import-test')->get($path));
        $this->assertDatabaseHas('daily_audio_practice_tracks', [
            'id' => self::TRACK_ID,
            'practice_id' => self::PRACTICE_ID,
            'status' => 'ready',
            'audio_url' => DailyAudioPracticeGeneration::audioUrl(
                self::PRACTICE_ID,
                self::TRACK_ID,
            ),
        ]);
        $this->assertSame(
            self::PRACTICE_CREATED_AT,
            DailyAudioPractice::query()->sole()->created_at->format('Y-m-d H:i:s.v'),
        );
        $this->assertSame(
            self::PRACTICE_UPDATED_AT,
            DailyAudioPractice::query()->sole()->updated_at->format('Y-m-d H:i:s.v'),
        );
        $this->assertSame(
            self::TRACK_CREATED_AT,
            DailyAudioPracticeTrack::query()->sole()->created_at->format('Y-m-d H:i:s.v'),
        );
        $this->assertSame(
            self::TRACK_UPDATED_AT,
            DailyAudioPracticeTrack::query()->sole()->updated_at->format('Y-m-d H:i:s.v'),
        );

        Sanctum::actingAs(User::query()->sole(), ['study:read']);
        $this->get(
            '/api/daily-audio-practice/'.self::PRACTICE_ID.
            '/tracks/'.self::TRACK_ID.'/audio',
        )
            ->assertOk()
            ->assertHeader('content-type', 'audio/mpeg')
            ->assertStreamedContent($contents);
    }

    public function test_retry_reuses_verified_file_and_canonical_url(): void
    {
        $this->putSourceFile(self::SOURCE_OBJECT_PATH, 'same-audio');

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->assertExitCode(0);
        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->assertExitCode(0);

        $path = DailyAudioPracticeGeneration::storagePath(self::PRACTICE_ID, self::TRACK_ID);
        $this->assertSame('same-audio', Storage::disk('daily-audio-import-test')->get($path));
        $this->assertSame(
            DailyAudioPracticeGeneration::audioUrl(self::PRACTICE_ID, self::TRACK_ID),
            DailyAudioPracticeTrack::query()->sole()->audio_url,
        );
        $this->assertSame(
            self::PRACTICE_UPDATED_AT,
            DailyAudioPractice::query()->sole()->updated_at->format('Y-m-d H:i:s.v'),
        );
        $this->assertSame(
            self::TRACK_UPDATED_AT,
            DailyAudioPracticeTrack::query()->sole()->updated_at->format('Y-m-d H:i:s.v'),
        );
    }

    public function test_repairs_timestamps_for_tracks_without_media(): void
    {
        $source = DB::connection('convolab_daily_audio_test_source');
        $source->table('daily_audio_practice_tracks')->insert([
            'id' => self::ERROR_TRACK_ID,
            'practiceId' => self::PRACTICE_ID,
            'mode' => 'dialogue',
            'status' => 'error',
            'audioUrl' => null,
            'createdAt' => '2026-06-24 14:46:18.562',
            'updatedAt' => '2026-06-24 15:18:16.680',
        ]);
        DailyAudioPracticeTrack::factory()->for(
            DailyAudioPractice::query()->sole(),
            'practice',
        )->create([
            'id' => self::ERROR_TRACK_ID,
            'mode' => 'dialogue',
            'status' => 'error',
            'audio_url' => null,
            'created_at' => '2026-06-24 14:46:19',
            'updated_at' => '2026-06-24 15:18:17',
        ]);
        $this->putSourceFile(self::SOURCE_OBJECT_PATH, 'ready-track-audio');

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->assertExitCode(0);

        $track = DailyAudioPracticeTrack::query()->findOrFail(self::ERROR_TRACK_ID);
        $this->assertSame('2026-06-24 14:46:18.562', $track->created_at->format('Y-m-d H:i:s.v'));
        $this->assertSame('2026-06-24 15:18:16.680', $track->updated_at->format('Y-m-d H:i:s.v'));
        $this->assertNull($track->audio_url);
    }

    public function test_rejects_an_invalid_source_timestamp_before_touching_storage_or_data(): void
    {
        DB::connection('convolab_daily_audio_test_source')
            ->table('daily_audio_practices')
            ->update(['updatedAt' => 'not-a-timestamp']);
        $this->putSourceFile(self::SOURCE_OBJECT_PATH, 'verified-audio');

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->expectsOutputToContain(
                'Convo Lab Daily Audio practice ['.self::PRACTICE_ID.
                '] updatedAt is not a valid database timestamp.',
            )
            ->assertExitCode(1);

        $this->assertLegacyTargetState();
    }

    public function test_empty_source_is_a_successful_no_op(): void
    {
        DB::connection('convolab_daily_audio_test_source')
            ->table('daily_audio_practice_tracks')
            ->delete();
        DailyAudioPracticeTrack::query()->update([
            'audio_url' => DailyAudioPracticeGeneration::audioUrl(
                self::PRACTICE_ID,
                self::TRACK_ID,
            ),
        ]);

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->expectsOutputToContain('Verified 0 historical Daily Audio tracks.')
            ->assertExitCode(0);

        $this->assertSame(
            DailyAudioPracticeGeneration::audioUrl(self::PRACTICE_ID, self::TRACK_ID),
            DailyAudioPracticeTrack::query()->sole()->audio_url,
        );
    }

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

            if ($forceFailure
                && str_contains($sql, 'update')
                && str_contains($sql, 'daily_audio_practice_tracks')) {
                $forceFailure = false;

                throw new \RuntimeException('forced Daily Audio update failure');
            }
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

    private function configurePostgresLockConnection(): void
    {
        $defaultConnection = DB::getDefaultConnection();

        if (DB::connection($defaultConnection)->getDriverName() !== 'pgsql') {
            return;
        }

        $this->lockConnection = 'convolab_daily_audio_lock_test';
        config([
            "database.connections.{$this->lockConnection}" => config(
                "database.connections.{$defaultConnection}",
            ),
            'cache.stores.database.connection' => $this->lockConnection,
            'cache.stores.database.lock_connection' => $this->lockConnection,
        ]);
        DB::purge($this->lockConnection);
        app('cache')->forgetDriver('database');
    }

    /**
     * @return array<string, mixed>
     */
    private function commandOptions(): array
    {
        return [
            '--source-connection' => 'convolab_daily_audio_test_source',
            '--source-media-root' => $this->sourceMediaRoot,
        ];
    }

    private function createSourceSchema(): void
    {
        $schema = Schema::connection('convolab_daily_audio_test_source');
        $schema->create('daily_audio_practices', function ($table): void {
            $table->text('id')->primary();
            $table->timestamp('createdAt', 3);
            $table->timestamp('updatedAt', 3);
        });
        $schema->create('daily_audio_practice_tracks', function ($table): void {
            $table->text('id')->primary();
            $table->text('practiceId');
            $table->text('mode');
            $table->text('status');
            $table->text('audioUrl')->nullable();
            $table->timestamp('createdAt', 3);
            $table->timestamp('updatedAt', 3);
        });
    }

    private function seedTarget(): void
    {
        $user = User::factory()->create();
        $practice = DailyAudioPractice::factory()->for($user)->create([
            'id' => self::PRACTICE_ID,
            'created_at' => '2026-06-24 14:46:19',
            'updated_at' => '2026-06-24 15:18:17',
        ]);
        DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
            'id' => self::TRACK_ID,
            'practice_id' => self::PRACTICE_ID,
            'mode' => 'drill',
            'status' => 'ready',
            'audio_url' => 'https://storage.googleapis.com/convolab-storage/legacy.mp3',
            'created_at' => '2026-06-24 14:46:19',
            'updated_at' => '2026-06-24 15:18:17',
        ]);
    }

    private function seedSource(): void
    {
        $source = DB::connection('convolab_daily_audio_test_source');
        $source->table('daily_audio_practices')->insert([
            'id' => self::PRACTICE_ID,
            'createdAt' => self::PRACTICE_CREATED_AT,
            'updatedAt' => self::PRACTICE_UPDATED_AT,
        ]);
        $source->table('daily_audio_practice_tracks')->insert([
            'id' => self::TRACK_ID,
            'practiceId' => self::PRACTICE_ID,
            'mode' => 'drill',
            'status' => 'ready',
            'audioUrl' => 'https://storage.googleapis.com/convolab-storage/'.
                self::SOURCE_OBJECT_PATH,
            'createdAt' => self::TRACK_CREATED_AT,
            'updatedAt' => self::TRACK_UPDATED_AT,
        ]);
    }

    private function putSourceFile(string $path, string $contents): void
    {
        $absolute = $this->sourceMediaRoot.DIRECTORY_SEPARATOR.
            str_replace('/', DIRECTORY_SEPARATOR, $path);
        $directory = dirname($absolute);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($absolute, $contents);
    }

    private function assertLegacyTargetState(): void
    {
        $this->assertSame(
            'https://storage.googleapis.com/convolab-storage/legacy.mp3',
            DailyAudioPracticeTrack::query()->sole()->audio_url,
        );
        Storage::disk('daily-audio-import-test')->assertMissing(
            DailyAudioPracticeGeneration::storagePath(self::PRACTICE_ID, self::TRACK_ID),
        );
    }
}
