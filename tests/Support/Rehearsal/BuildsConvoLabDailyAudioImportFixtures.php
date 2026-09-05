<?php

namespace Tests\Support\Rehearsal;

use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

trait BuildsConvoLabDailyAudioImportFixtures
{
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
