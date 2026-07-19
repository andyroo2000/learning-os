<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\ListDailyAudioPracticesAction;
use App\Domain\Study\Actions\ShowDailyAudioPracticeAction;
use App\Domain\Study\Actions\ShowDailyAudioPracticeStatusAction;
use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class DailyAudioPracticeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_require_authentication(): void
    {
        $id = '33cb3d35-8566-4dd5-aebe-af1725c3d18a';

        $this->getJson('/api/daily-audio-practice')->assertUnauthorized();
        $this->getJson("/api/daily-audio-practice/{$id}")->assertUnauthorized();
        $this->getJson("/api/daily-audio-practice/{$id}/status")->assertUnauthorized();
    }

    public function test_index_returns_fourteen_recent_practices_with_sorted_summary_tracks(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        foreach (range(0, 14) as $daysAgo) {
            DailyAudioPractice::factory()->for($user)->create([
                'practice_date' => today()->subDays($daysAgo),
            ]);
        }
        DailyAudioPractice::factory()->for($otherUser)->create();

        $newest = DailyAudioPractice::query()
            ->where('user_id', $user->id)
            ->orderByDesc('practice_date')
            ->firstOrFail();
        $laterTrack = DailyAudioPracticeTrack::factory()->for($newest, 'practice')->create([
            'mode' => 'story',
            'sort_order' => 2,
        ]);
        $earlierTrack = DailyAudioPracticeTrack::factory()->for($newest, 'practice')->create([
            'mode' => 'drill',
            'sort_order' => 0,
        ]);

        $response = $this->getJson('/api/daily-audio-practice')
            ->assertOk()
            ->assertJsonCount(ListDailyAudioPracticesAction::RECENT_LIMIT)
            ->assertJsonPath('0.id', $newest->id)
            ->assertJsonPath('0.tracks.0.id', $earlierTrack->id)
            ->assertJsonPath('0.tracks.1.id', $laterTrack->id)
            ->assertJsonMissingPath('0.tracks.0.scriptUnitsJson')
            ->assertJsonMissingPath('0.tracks.0.timingData')
            ->assertJsonMissingPath('0.tracks.0.generationMetadataJson');

        $this->assertSame(
            collect($response->json())->pluck('practiceDate')->sortDesc()->values()->all(),
            collect($response->json())->pluck('practiceDate')->all(),
        );
    }

    public function test_index_uses_two_bounded_queries_without_loading_large_track_payloads(): void
    {
        $user = User::factory()->create();
        foreach (range(0, 2) as $daysAgo) {
            $practice = DailyAudioPractice::factory()->for($user)->create([
                'practice_date' => today()->subDays($daysAgo),
            ]);
            DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
                'script_units_json' => [['type' => 'speech', 'text' => str_repeat('a', 10_000)]],
                'timing_data' => [['unitIndex' => 0, 'startTime' => 0, 'endTime' => 1]],
                'generation_metadata_json' => ['provider' => 'test'],
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            app(ListDailyAudioPracticesAction::class)->handle($user->id);
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertCount(2, $queries, $queries->pluck('query')->implode("\n"));
        $trackQuery = $queries->first(
            fn (array $query): bool => str_contains($query['query'], 'daily_audio_practice_tracks'),
        );
        $this->assertIsArray($trackQuery);
        $this->assertStringNotContainsString('script_units_json', $trackQuery['query']);
        $this->assertStringNotContainsString('timing_data', $trackQuery['query']);
        $this->assertStringNotContainsString('generation_metadata_json', $trackQuery['query']);
    }

    public function test_show_returns_the_full_compatibility_shape(): void
    {
        $user = $this->signIn();
        $practice = DailyAudioPractice::factory()->for($user)->create([
            'practice_date' => '2026-07-14',
            'convolab_user_id' => 'source-user-1',
            'source_card_ids_json' => ['card-1'],
            'selection_summary_json' => ['selectedCount' => 1],
        ]);
        $track = DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
            'script_units_json' => [['type' => 'speech', 'text' => '猫']],
            'timing_data' => [['unitIndex' => 0, 'startTime' => 0, 'endTime' => 1]],
            'generation_metadata_json' => ['provider' => 'test'],
        ]);

        $this->getJson("/api/daily-audio-practice/{$practice->id}")
            ->assertOk()
            ->assertJsonPath('id', $practice->id)
            ->assertJsonPath('userId', 'source-user-1')
            ->assertJsonPath('practiceDate', '2026-07-14')
            ->assertJsonPath('sourceCardIdsJson.0', 'card-1')
            ->assertJsonPath('selectionSummaryJson.selectedCount', 1)
            ->assertJsonPath('tracks.0.id', $track->id)
            ->assertJsonPath('tracks.0.scriptUnitsJson.0.text', '猫')
            ->assertJsonPath('tracks.0.timingData.0.endTime', 1)
            ->assertJsonPath('tracks.0.generationMetadataJson.provider', 'test');
    }

    public function test_show_includes_nullable_compatibility_keys(): void
    {
        $practice = DailyAudioPractice::factory()->for($this->signIn())->create([
            'source_card_ids_json' => null,
            'selection_summary_json' => null,
            'error_message' => null,
        ]);
        DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
            'script_units_json' => null,
            'audio_url' => null,
            'timing_data' => null,
            'approx_duration_seconds' => null,
            'generation_metadata_json' => null,
            'error_message' => null,
        ]);

        $payload = $this->getJson("/api/daily-audio-practice/{$practice->id}")
            ->assertOk()
            ->json();

        foreach (['sourceCardIdsJson', 'selectionSummaryJson', 'errorMessage'] as $key) {
            $this->assertArrayHasKey($key, $payload);
            $this->assertNull($payload[$key]);
        }
        foreach ([
            'scriptUnitsJson',
            'audioUrl',
            'timingData',
            'approxDurationSeconds',
            'generationMetadataJson',
            'errorMessage',
        ] as $key) {
            $this->assertArrayHasKey($key, $payload['tracks'][0]);
            $this->assertNull($payload['tracks'][0][$key]);
        }
    }

    public function test_status_derives_durable_progress_from_completed_tracks(): void
    {
        $practice = DailyAudioPractice::factory()->for($this->signIn())->create([
            'status' => 'generating',
        ]);
        foreach ([
            ['drill', 'ready', 0],
            ['dialogue', 'skipped', 1],
            ['story', 'generating', 2],
        ] as [$mode, $status, $sortOrder]) {
            DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
                'mode' => $mode,
                'status' => $status,
                'sort_order' => $sortOrder,
            ]);
        }

        $this->getJson("/api/daily-audio-practice/{$practice->id}/status")
            ->assertOk()
            ->assertJsonPath('id', $practice->id)
            ->assertJsonPath('status', 'generating')
            ->assertJsonPath('progress', 66)
            ->assertJsonCount(3, 'tracks')
            ->assertJsonPath('tracks.0.mode', 'drill')
            ->assertJsonPath('tracks.2.mode', 'story');
    }

    public function test_status_fallback_uses_the_three_track_compatibility_contract(): void
    {
        $practice = DailyAudioPractice::factory()->for($this->signIn())->create([
            'status' => 'generating',
        ]);
        DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
            'status' => 'ready',
        ]);

        $this->getJson("/api/daily-audio-practice/{$practice->id}/status")
            ->assertOk()
            ->assertJsonPath('progress', 33);
    }

    public function test_status_uses_two_lean_queries(): void
    {
        $user = User::factory()->create();
        $practice = DailyAudioPractice::factory()->for($user)->create([
            'status' => 'generating',
            'source_card_ids_json' => array_fill(0, 100, 'card-id'),
        ]);
        DailyAudioPracticeTrack::factory()->for($practice, 'practice')->create([
            'script_units_json' => [['type' => 'speech', 'text' => str_repeat('a', 10_000)]],
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            app(ShowDailyAudioPracticeStatusAction::class)->handle($user->id, $practice->id);
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertCount(2, $queries, $queries->pluck('query')->implode("\n"));
        $sql = $queries->pluck('query')->implode("\n");
        $this->assertStringNotContainsString('source_card_ids_json', $sql);
        $this->assertStringNotContainsString('script_units_json', $sql);
        $this->assertStringNotContainsString('timing_data', $sql);
        $this->assertStringNotContainsString('generation_metadata_json', $sql);
    }

    public function test_show_and_status_hide_other_users_practices(): void
    {
        $this->signIn();
        $practice = DailyAudioPractice::factory()->for(User::factory()->create())->create();

        $this->getJson("/api/daily-audio-practice/{$practice->id}")->assertNotFound();
        $this->getJson("/api/daily-audio-practice/{$practice->id}/status")->assertNotFound();
    }

    public function test_routes_reject_malformed_ids_without_querying_another_shape(): void
    {
        $this->signIn();

        $this->getJson('/api/daily-audio-practice/not-a-uuid')->assertNotFound();
        $this->getJson('/api/daily-audio-practice/not-a-uuid/status')->assertNotFound();
    }

    public function test_show_actions_reject_malformed_ids_before_querying_uuid_columns(): void
    {
        $user = User::factory()->create();

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            foreach ([ShowDailyAudioPracticeAction::class, ShowDailyAudioPracticeStatusAction::class] as $action) {
                try {
                    app($action)->handle($user->id, 'not-a-uuid');
                    $this->fail("{$action} should reject malformed UUIDs.");
                } catch (NotFoundHttpException) {
                    // Expected hidden-not-found contract.
                }
            }
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $dailyAudioQueries = $queries->filter(
            fn (array $query): bool => str_contains($query['query'], 'daily_audio_practices'),
        );
        $this->assertCount(0, $dailyAudioQueries, $queries->pluck('query')->implode("\n"));
    }
}
