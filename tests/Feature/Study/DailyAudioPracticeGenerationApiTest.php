<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use App\Domain\Study\Support\DailyAudioPracticeGenerationRateLimiter;
use App\Jobs\ProcessDailyAudioPractice;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DailyAudioPracticeGenerationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_and_audio_routes_require_authentication(): void
    {
        $practiceId = '33cb3d35-8566-4dd5-aebe-af1725c3d18a';
        $trackId = '4762c0e6-fb17-42a6-8284-8a9de93620f0';

        $this->postJson('/api/daily-audio-practice')->assertUnauthorized();
        $this->getJson("/api/daily-audio-practice/{$practiceId}/tracks/{$trackId}/audio")
            ->assertUnauthorized();
    }

    public function test_it_creates_and_queues_a_same_day_practice_in_the_device_timezone(): void
    {
        Queue::fake();
        $user = $this->signIn();
        Carbon::setTestNow('2026-07-19T01:30:00Z');

        try {
            $response = $this->postJson('/api/daily-audio-practice', [
                'timeZone' => 'America/Los_Angeles',
                'targetDurationMinutes' => 45,
            ]);
        } finally {
            Carbon::setTestNow();
        }

        $response
            ->assertStatus(202)
            ->assertJsonPath('practiceDate', '2026-07-18')
            ->assertJsonPath('status', 'generating')
            ->assertJsonPath('targetDurationMinutes', 45)
            ->assertJsonPath('targetLanguage', 'ja')
            ->assertJsonPath('nativeLanguage', 'en')
            ->assertJsonCount(3, 'tracks')
            ->assertJsonPath('tracks.0.mode', 'drill')
            ->assertJsonPath('tracks.0.status', 'draft')
            ->assertJsonPath('tracks.1.mode', 'dialogue')
            ->assertJsonPath('tracks.1.status', 'skipped')
            ->assertJsonPath(
                'tracks.1.generationMetadataJson.reason',
                DailyAudioPracticeGeneration::SKIPPED_TRACK_METADATA['reason'],
            )
            ->assertJsonPath('tracks.2.mode', 'story')
            ->assertJsonPath('tracks.2.status', 'skipped');

        $practice = DailyAudioPractice::query()->sole();
        $this->assertSame($user->id, $practice->user_id);
        Queue::assertPushed(
            ProcessDailyAudioPractice::class,
            fn (ProcessDailyAudioPractice $job): bool => $job->practiceId === $practice->id,
        );
    }

    public function test_it_uses_utc_and_the_default_duration_when_options_are_omitted(): void
    {
        Queue::fake();
        $this->signIn();
        Carbon::setTestNow('2026-07-19T23:30:00Z');

        try {
            $this->postJson('/api/daily-audio-practice')
                ->assertStatus(202)
                ->assertJsonPath('practiceDate', '2026-07-19')
                ->assertJsonPath(
                    'targetDurationMinutes',
                    DailyAudioPracticeGeneration::DEFAULT_TARGET_DURATION_MINUTES,
                );
        } finally {
            Carbon::setTestNow();
        }
    }

    #[DataProvider('invalidGenerationInputProvider')]
    public function test_it_validates_generation_options(
        array $payload,
        string $errorKey,
    ): void {
        Queue::fake();
        $this->signIn();

        $this->postJson('/api/daily-audio-practice', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKey);

        $this->assertDatabaseCount('daily_audio_practices', 0);
        Queue::assertNothingPushed();
    }

    public static function invalidGenerationInputProvider(): array
    {
        return [
            'bad timezone' => [['timeZone' => 'Not/A_Zone'], 'timeZone'],
            'oversized timezone' => [['timeZone' => str_repeat('a', 65)], 'timeZone'],
            'timezone array' => [['timeZone' => ['UTC']], 'timeZone'],
            'duration below minimum' => [['targetDurationMinutes' => 4], 'targetDurationMinutes'],
            'duration above maximum' => [['targetDurationMinutes' => 61], 'targetDurationMinutes'],
            'fractional duration' => [['targetDurationMinutes' => 30.5], 'targetDurationMinutes'],
        ];
    }

    public function test_generation_is_rate_limited_per_user_without_blocking_other_users(): void
    {
        Queue::fake();
        $limiter = new DailyAudioPracticeGenerationRateLimiter;
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        try {
            RateLimiter::for(
                DailyAudioPracticeGenerationRateLimiter::NAME,
                static fn (Request $request): Limit => Limit::perMinute(1)->by(
                    $testBucket.'|'.$limiter->keyFor(
                        $request->user()?->getAuthIdentifier(),
                        $request->ip(),
                    ),
                ),
            );

            $this->postJson('/api/daily-audio-practice')->assertStatus(202);

            $this->signIn($otherUser);
            $this->postJson('/api/daily-audio-practice')->assertStatus(202);

            $this->signIn($user);
            $this->postJson('/api/daily-audio-practice')
                ->assertTooManyRequests()
                ->assertHeader('X-RateLimit-Limit', '1')
                ->assertHeader('X-RateLimit-Remaining', '0')
                ->assertHeader('Retry-After');
        } finally {
            RateLimiter::for(
                DailyAudioPracticeGenerationRateLimiter::NAME,
                static fn (Request $request): Limit => $limiter->limit($request),
            );
        }

        $this->assertDatabaseCount('daily_audio_practices', 2);
        Queue::assertPushed(ProcessDailyAudioPractice::class, 2);
    }

    public function test_retry_reuses_the_same_practice_and_resets_generated_track_state(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $practice = DailyAudioPractice::factory()->for($user)->create([
            'practice_date' => '2026-07-19',
            'status' => 'ready',
            'target_duration_minutes' => 30,
            'error_message' => 'Old error.',
        ]);
        foreach (DailyAudioPracticeGeneration::TRACKS as $trackConfig) {
            DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
                'mode' => $trackConfig['mode'],
                'title' => $trackConfig['title'],
                'sort_order' => $trackConfig['sortOrder'],
                'status' => 'ready',
                'script_units_json' => [['type' => 'speech']],
                'audio_url' => '/old.mp3',
                'timing_data' => [['unitIndex' => 0]],
                'generation_metadata_json' => ['old' => true],
                'error_message' => 'Old error.',
            ]);
        }
        Carbon::setTestNow('2026-07-19T12:00:00Z');

        try {
            $response = $this->postJson('/api/daily-audio-practice', [
                'timeZone' => 'UTC',
                'targetDurationMinutes' => 20,
            ]);
        } finally {
            Carbon::setTestNow();
        }

        $response
            ->assertStatus(202)
            ->assertJsonPath('id', $practice->id)
            ->assertJsonPath('status', 'generating')
            ->assertJsonPath('targetDurationMinutes', 20)
            ->assertJsonPath('tracks.0.status', 'draft')
            ->assertJsonPath('tracks.0.scriptUnitsJson', null)
            ->assertJsonPath('tracks.0.audioUrl', null)
            ->assertJsonPath('tracks.1.status', 'skipped')
            ->assertJsonPath('tracks.2.status', 'skipped');

        $this->assertDatabaseCount('daily_audio_practices', 1);
        $this->assertDatabaseCount('daily_audio_practice_tracks', 3);
        Queue::assertPushed(ProcessDailyAudioPractice::class, 1);
    }

    public function test_retry_while_generating_requeues_without_destroying_current_progress(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $practice = DailyAudioPractice::factory()->for($user)->create([
            'status' => 'generating',
        ]);
        $drill = DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
            'status' => 'generating',
            'audio_url' => null,
            'script_units_json' => [['type' => 'L2', 'text' => '猫']],
        ]);
        foreach ([
            ['dialogue', 1],
            ['story', 2],
        ] as [$mode, $sortOrder]) {
            DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
                'mode' => $mode,
                'sort_order' => $sortOrder,
                'status' => 'skipped',
            ]);
        }
        $this->postJson('/api/daily-audio-practice')
            ->assertStatus(202)
            ->assertJsonPath('id', $practice->id)
            ->assertJsonPath('tracks.0.id', $drill->id)
            ->assertJsonPath('tracks.0.status', 'generating')
            ->assertJsonPath('tracks.0.scriptUnitsJson.0.text', '猫');

        Queue::assertPushed(ProcessDailyAudioPractice::class, 1);
    }

    public function test_queue_dispatch_failure_is_persisted_as_an_actionable_error(): void
    {
        $this->signIn();
        Bus::shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('Queue unavailable.'));

        $response = $this->postJson('/api/daily-audio-practice');

        $response
            ->assertStatus(202)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath(
                'errorMessage',
                DailyAudioPracticeGeneration::QUEUE_FAILED_MESSAGE,
            )
            ->assertJsonPath('tracks.0.status', 'error')
            ->assertJsonPath('tracks.1.status', 'skipped');
    }

    public function test_ready_audio_can_only_be_streamed_by_its_owner(): void
    {
        Storage::fake('media');
        $user = $this->signIn();
        $practice = DailyAudioPractice::factory()->for($user)->create();
        $track = DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
            'mode' => 'drill',
            'status' => 'ready',
        ]);
        $path = DailyAudioPracticeGeneration::storagePath($practice->id, $track->id);
        Storage::disk('media')->put($path, 'mp3-bytes');

        $response = $this->get(
            "/api/daily-audio-practice/{$practice->id}/tracks/{$track->id}/audio",
        );

        $response
            ->assertOk()
            ->assertHeader('content-type', 'audio/mpeg');
        $this->assertSame('mp3-bytes', $response->streamedContent());

        $this->signIn(User::factory()->create());
        $this->get(
            "/api/daily-audio-practice/{$practice->id}/tracks/{$track->id}/audio",
        )->assertNotFound();
    }

    public function test_audio_stream_hides_non_ready_missing_and_malformed_targets(): void
    {
        Storage::fake('media');
        $practice = DailyAudioPractice::factory()->for($this->signIn())->create();
        $track = DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
            'status' => 'generating',
        ]);
        $path = "/api/daily-audio-practice/{$practice->id}/tracks/{$track->id}/audio";

        $this->get($path)->assertNotFound();

        $track->status = 'ready';
        $track->save();
        $this->get($path)->assertNotFound();

        $this->get(
            "/api/daily-audio-practice/not-a-uuid/tracks/{$track->id}/audio",
        )->assertNotFound();
    }
}
