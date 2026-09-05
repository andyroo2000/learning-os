<?php

namespace Tests\Feature\Rehearsal;

use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Rehearsal\BuildsConvoLabDailyAudioImportFixtures;
use Tests\TestCase;

class ConvoLabDailyAudioImportCommandTest extends TestCase
{
    use BuildsConvoLabDailyAudioImportFixtures;
    use RefreshDatabase;

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

    public function test_normalizes_postgres_shortened_fractional_timestamps(): void
    {
        DB::connection('convolab_daily_audio_test_source')
            ->table('daily_audio_practices')
            ->update([
                // Postgres renders a stored .500 millisecond fraction as .5 by default.
                'createdAt' => '2026-06-24 14:46:18.5',
                'updatedAt' => '2026-06-24 15:18:16.05',
            ]);
        $this->putSourceFile(self::SOURCE_OBJECT_PATH, 'verified-audio');

        $this->artisan('migration:import-convolab-daily-audio', $this->commandOptions())
            ->assertExitCode(0);

        $practice = DailyAudioPractice::query()->sole();
        $this->assertSame('2026-06-24 14:46:18.500', $practice->created_at->format('Y-m-d H:i:s.v'));
        $this->assertSame('2026-06-24 15:18:16.050', $practice->updated_at->format('Y-m-d H:i:s.v'));
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
}
