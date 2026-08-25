<?php

namespace Tests\Feature\Contracts;

use App\Domain\Calendar\Actions\ShowGoogleCalendarConnectionAction;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Calendar\Models\GoogleCalendarEventMirror;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\BuildPersonalWeeklyRecapAction;
use App\Domain\Study\Actions\BuildStudyActivityAnalyticsAction;
use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Domain\Study\Models\StudyActivitySession;
use App\Http\Resources\Study\DailyAudioPracticeResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\Contracts\CompatibilityFixtureRepository;
use Tests\TestCase;

class CompatibilityPayloadContractFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_calendar_connection_action_matches_the_canonical_fixture(): void
    {
        $cases = [];
        $disconnectedUser = User::factory()->create();
        $show = app(ShowGoogleCalendarConnectionAction::class);
        $cases[] = $this->case('disconnected', 'Disconnected state keeps every nullable field present.', $show->handle($disconnectedUser->id));

        $this->travelTo(Carbon::parse('2026-08-24T18:00:00Z'), function () use (&$cases, $show): void {
            $user = User::factory()->create();
            $connection = GoogleCalendarConnection::query()->forceCreate([
                'user_id' => $user->id,
                'provider_account_id' => 'google-account',
                'account_email' => 'andrew@example.com',
                'access_token' => 'encrypted-by-cast',
                'refresh_token' => 'encrypted-by-cast',
                'token_expires_at' => Carbon::parse('2026-08-24T20:00:00Z'),
                'scopes' => ['calendar.readonly'],
                'settings' => [
                    'calendarIds' => ['primary'],
                    'titleMatchTerms' => ['iTalki'],
                    'syncEnabled' => true,
                ],
                'sync_cursors' => ['primary' => 'cursor'],
                'connected_at' => Carbon::parse('2026-08-15T14:00:00Z'),
                'last_synced_at' => Carbon::parse('2026-08-24T17:45:12Z'),
                'sync_status' => 'failed',
                'sync_error_code' => 'provider_unavailable',
                'sync_status_at' => Carbon::parse('2026-08-24T17:46:13Z'),
            ]);
            GoogleCalendarEventMirror::query()->forceCreate([
                'google_calendar_connection_id' => $connection->id,
                'source_key' => hash('sha256', 'fixture-next-italki'),
                'calendar_id' => 'primary',
                'provider_event_id' => 'fixture-next-italki',
                'status' => 'confirmed',
                'title' => 'iTalki with Yuki',
                'starts_at' => Carbon::parse('2026-08-24T19:00:00Z'),
                'ends_at' => Carbon::parse('2026-08-24T20:00:00Z'),
                'all_day' => false,
                'observed_at' => Carbon::parse('2026-08-24T18:00:00Z'),
            ]);

            $cases[] = $this->case('connected-with-next-lesson', 'Connected state includes settings, failed sync diagnostics, and the next matched lesson.', $show->handle($user->id));
        });

        $this->assertFixtureCases('google-calendar-connection.v1', $cases);
    }

    public function test_study_activity_analytics_action_matches_the_canonical_fixture(): void
    {
        $user = User::factory()->create();
        $sessions = [
            ['review', 'card_review', '2026-07-28T03:30:00Z', '2026-07-28T04:30:00Z', 3_600_001],
            ['listen', 'daily_audio', '2026-07-28T04:15:00Z', '2026-07-28T05:45:00Z', 900_001],
            ['create', 'card_creation', '2026-07-28T06:00:00Z', '2026-07-28T09:00:00Z', 1_000],
            ['immerse', 'tv', '2026-07-28T09:00:00Z', '2026-07-28T09:10:00Z', 600_000],
            ['conversation', 'conversation', '2026-07-28T10:00:00Z', '2026-07-28T10:20:00Z', 1_200_000],
            ['wanikani', 'wanikani_review', '2026-07-28T11:00:00Z', '2026-07-28T11:05:00Z', 300_000],
        ];
        foreach ($sessions as $index => [$category, $activity, $startedAt, $endedAt, $durationMs]) {
            StudyActivitySession::query()->forceCreate([
                'user_id' => $user->id,
                'client_session_id' => "contract-analytics-{$index}",
                'category' => $category,
                'activity' => $activity,
                'source' => 'automatic',
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'duration_ms' => $durationMs,
            ]);
        }

        $payload = app(BuildStudyActivityAnalyticsAction::class)->handle(
            $user->id,
            new DateTimeZone('America/New_York'),
            2,
            CarbonImmutable::parse('2026-07-28T12:00:00-04:00'),
            adaptiveAllTime: true,
        );

        $this->assertFixtureCases('study-activity-analytics.v1', [
            $this->case('cross-midnight-all-categories', 'All categories plus cross-midnight and fractional allocation remainders.', $payload),
        ]);
    }

    public function test_daily_audio_resource_matches_the_canonical_fixture(): void
    {
        $user = User::factory()->create();
        $ready = DailyAudioPractice::factory()->for($user)->create([
            'id' => '10000000-0000-4000-8000-000000000001',
            'convolab_user_id' => '20000000-0000-4000-8000-000000000001',
            'practice_date' => '2026-08-24',
            'status' => 'ready',
            'target_duration_minutes' => 25,
            'target_language' => 'ja',
            'native_language' => 'en',
            'source_card_ids_json' => ['card-a', 'card-b'],
            'selection_summary_json' => ['dueCount' => 1, 'learningCount' => 1],
            'error_message' => null,
            'created_at' => Carbon::parse('2026-08-24T08:00:00Z'),
            'updated_at' => Carbon::parse('2026-08-24T08:05:00Z'),
        ]);
        DailyAudioPracticeTrack::factory()->for($ready, 'practice')->create([
            'id' => '30000000-0000-4000-8000-000000000001',
            'mode' => 'drill',
            'status' => 'ready',
            'title' => 'Recall drill',
            'sort_order' => 0,
            'script_units_json' => [['kind' => 'target_language', 'text' => '会社']],
            'audio_url' => '/audio/drill.mp3',
            'timing_data' => [['startMs' => 0, 'endMs' => 1200]],
            'approx_duration_seconds' => 120,
            'generation_metadata_json' => ['voice' => 'fishaudio:nanami'],
            'error_message' => null,
            'created_at' => Carbon::parse('2026-08-24T08:01:00Z'),
            'updated_at' => Carbon::parse('2026-08-24T08:04:00Z'),
        ]);
        DailyAudioPracticeTrack::factory()->for($ready, 'practice')->create([
            'id' => '30000000-0000-4000-8000-000000000002',
            'mode' => 'context',
            'status' => 'skipped',
            'title' => 'Context story',
            'sort_order' => 1,
            'script_units_json' => null,
            'audio_url' => null,
            'timing_data' => null,
            'approx_duration_seconds' => null,
            'generation_metadata_json' => ['reason' => 'insufficient_cards'],
            'error_message' => null,
            'created_at' => Carbon::parse('2026-08-24T08:02:00Z'),
            'updated_at' => Carbon::parse('2026-08-24T08:03:00Z'),
        ]);
        $error = DailyAudioPractice::factory()->for($user)->create([
            'id' => '10000000-0000-4000-8000-000000000002',
            'convolab_user_id' => '20000000-0000-4000-8000-000000000001',
            'practice_date' => '2026-08-25',
            'status' => 'error',
            'source_card_ids_json' => [],
            'selection_summary_json' => null,
            'error_message' => 'Generation failed.',
            'created_at' => Carbon::parse('2026-08-25T09:00:00Z'),
            'updated_at' => Carbon::parse('2026-08-25T09:01:00Z'),
        ]);

        $ready->load(['tracks' => fn ($query) => $query->orderBy('sort_order')]);
        $error->load('tracks');
        $this->assertFixtureCases('daily-audio-practice.v1', [
            $this->case('ready-with-ready-and-skipped-tracks', 'Ready practice preserves nested script, timing, metadata, and nullable skipped-track fields.', $this->dailyAudioPayload($ready)),
            $this->case('error-without-tracks', 'Error practice keeps nullable selection data and an empty loaded track list.', $this->dailyAudioPayload($error)),
        ]);
    }

    public function test_personal_weekly_recap_action_matches_the_canonical_fixture(): void
    {
        $action = app(BuildPersonalWeeklyRecapAction::class);
        $timezone = new DateTimeZone('America/New_York');
        $now = CarbonImmutable::parse('2026-08-12T12:00:00-04:00');
        $emptyUser = User::factory()->create();
        $cases = [
            $this->case('empty-completed-week', 'Empty weeks preserve null recall and best-day values.', $action->handle($emptyUser->id, $timezone, 1, $now)),
        ];

        $user = User::factory()->create();
        $this->activity($user, 'review', 'card_review', '2026-08-03T12:00:00Z', '2026-08-03T13:00:00Z', 3_600_000);
        $this->activity($user, 'conversation', 'conversation', '2026-08-04T12:00:00Z', '2026-08-04T12:30:00Z', 1_800_000);
        $this->activity($user, 'listen', 'daily_audio', '2026-07-27T12:00:00Z', '2026-07-27T12:15:00Z', 900_000);
        $this->reviewedCard($user, '2026-08-03T12:00:00Z', '2026-08-03T13:00:00Z', CardReviewRating::Good);
        $this->reviewedCard($user, '2026-08-04T12:00:00Z', '2026-08-04T13:00:00Z', CardReviewRating::Again);
        $this->reviewedCard($user, '2026-07-27T12:00:00Z', '2026-07-27T13:00:00Z', CardReviewRating::Hard);
        $cases[] = $this->case('owned-study-review-and-introduction-metrics', 'Completed and previous weeks include category totals, recall, introductions, and best day.', $action->handle($user->id, $timezone, 1, $now));

        $this->assertFixtureCases('personal-weekly-recap.v1', $cases);
    }

    private function activity(User $user, string $category, string $activity, string $startedAt, string $endedAt, int $durationMs): void
    {
        StudyActivitySession::query()->forceCreate([
            'user_id' => $user->id,
            'client_session_id' => hash('sha256', "{$category}|{$activity}|{$startedAt}|{$endedAt}|{$durationMs}"),
            'category' => $category,
            'activity' => $activity,
            'source' => 'automatic',
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_ms' => $durationMs,
        ]);
    }

    private function reviewedCard(User $user, string $introducedAt, string $reviewedAt, CardReviewRating $rating): void
    {
        $card = Card::factory()->for($this->deckFor($user))->create(['introduced_at' => $introducedAt]);
        CardReviewEvent::factory()->for($card)->create(['reviewed_at' => $reviewedAt, 'rating' => $rating]);
    }

    /** @return array<string, mixed> */
    private function dailyAudioPayload(DailyAudioPractice $practice): array
    {
        // Mirror the show controller so nested resource collections complete their
        // serialization inside JsonResponse without adding a top-level data wrapper.
        return response()->json(
            DailyAudioPracticeResource::make($practice)->resolve(request()),
        )->getData(true);
    }

    /** @return array{id: string, description: string, payload: array<string, mixed>} */
    private function case(string $id, string $description, array $payload): array
    {
        return compact('id', 'description', 'payload');
    }

    /** @param list<array{id: string, description: string, payload: array<string, mixed>}> $cases */
    private function assertFixtureCases(string $fixtureId, array $cases): void
    {
        $this->assertSame(CompatibilityFixtureRepository::fixture($fixtureId)['cases'], $cases);
    }
}
