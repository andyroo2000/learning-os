<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\BuildPersonalWeeklyRecapAction;
use App\Domain\Study\Models\StudyActivitySession;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class PersonalWeeklyRecapApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_it_requires_authentication_and_validates_calendar_options(): void
    {
        $this->getJson('/api/study/weekly-recap?timezone=UTC&weekStartsOn=1')
            ->assertUnauthorized();

        $this->signIn();
        $this->getJson('/api/study/weekly-recap?timezone=Moon%2FBase&weekStartsOn=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['timezone', 'weekStartsOn']);
        $this->getJson('/api/study/weekly-recap?timezone[]=UTC&weekStartsOn[]=1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['timezone', 'weekStartsOn']);
    }

    public function test_it_returns_the_exact_empty_completed_week_contract(): void
    {
        $this->signIn();
        $this->travelTo(CarbonImmutable::parse('2026-08-12T16:00:00Z'));

        try {
            $this->getJson('/api/study/weekly-recap?timezone=America%2FNew_York&weekStartsOn=1')
                ->assertOk()
                ->assertExactJson([
                    'generatedAt' => '2026-08-12T16:00:00.000000Z',
                    'week' => [
                        'startsAt' => '2026-08-02T04:00:00.000000Z',
                        'endsAt' => '2026-08-09T04:00:00.000000Z',
                        'totalMs' => 0,
                        'activeDays' => 0,
                        'categories' => [
                            'review' => 0,
                            'listen' => 0,
                            'create' => 0,
                            'immerse' => 0,
                            'conversation' => 0,
                            'wanikani' => 0,
                        ],
                        'reviewCount' => 0,
                        'recallRate' => null,
                        'newCardsIntroduced' => 0,
                        'bestDay' => null,
                    ],
                    'previousWeek' => [
                        'totalMs' => 0,
                        'activeDays' => 0,
                        'reviewCount' => 0,
                        'recallRate' => null,
                        'newCardsIntroduced' => 0,
                    ],
                ]);
        } finally {
            $this->travelBack();
        }
    }

    public function test_it_caches_a_completed_week_briefly_and_refreshes_after_expiry(): void
    {
        $user = $this->signIn();
        $other = User::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-08-12T16:00:00Z'));

        try {
            $this->createActivitySession(
                $user,
                'review',
                '2026-08-03T12:00:00Z',
                '2026-08-03T13:00:00Z',
                3_600_000,
            );

            $this->getJson('/api/study/weekly-recap?timezone=America%2FNew_York&weekStartsOn=2')
                ->assertOk()
                ->assertJsonPath('week.totalMs', 3_600_000);

            $this->createActivitySession(
                $user,
                'conversation',
                '2026-08-04T12:00:00Z',
                '2026-08-04T12:30:00Z',
                1_800_000,
            );

            $this->createActivitySession(
                $other,
                'conversation',
                '2026-08-05T12:00:00Z',
                '2026-08-05T12:20:00Z',
                1_200_000,
            );
            $this->actingAs($other)
                ->getJson('/api/study/weekly-recap?timezone=America%2FNew_York&weekStartsOn=2')
                ->assertOk()
                ->assertJsonPath('week.totalMs', 1_200_000);

            $this->actingAs($user)
                ->getJson('/api/study/weekly-recap?timezone=America%2FNew_York&weekStartsOn=2')
                ->assertOk()
                ->assertJsonPath('week.totalMs', 3_600_000);

            $this->travel(16)->minutes();
            $this->actingAs($user)
                ->getJson('/api/study/weekly-recap?timezone=America%2FNew_York&weekStartsOn=2')
                ->assertOk()
                ->assertJsonPath('week.totalMs', 5_400_000);
        } finally {
            $this->travelBack();
        }
    }

    public function test_it_reports_owned_historical_metrics_with_half_open_boundaries(): void
    {
        $user = $this->signIn();
        $other = User::factory()->create();
        $this->travelTo(CarbonImmutable::parse('2026-08-12T16:00:00Z'));

        try {
            $this->createActivitySession($user, 'review', '2026-08-03T12:00:00Z', '2026-08-03T13:00:00Z', 3_600_000);
            $this->createActivitySession($user, 'conversation', '2026-07-27T12:00:00Z', '2026-07-27T12:30:00Z', 1_800_000);
            $this->createActivitySession($other, 'review', '2026-08-03T12:00:00Z', '2026-08-03T14:00:00Z', 7_200_000);

            $activeCard = $this->cardFor($user, ['introduced_at' => '2026-08-03T12:00:00Z']);
            $deletedCard = $this->cardFor($user, ['introduced_at' => '2026-08-04T12:00:00Z']);
            $deletedDeckCard = $this->cardFor($user, ['introduced_at' => '2026-08-05T12:00:00Z']);
            $previousCard = $this->cardFor($user, ['introduced_at' => '2026-07-26T04:00:00Z']);
            $excludedCard = $this->cardFor($user, ['introduced_at' => '2026-08-09T04:00:00Z']);
            $otherCard = $this->cardFor($other, ['introduced_at' => '2026-08-03T12:00:00Z']);

            $this->review($activeCard, CardReviewRating::Good, '2026-08-02T04:00:00Z');
            $this->review($deletedCard, CardReviewRating::Again, '2026-08-04T12:00:00Z');
            $this->review($deletedDeckCard, CardReviewRating::Easy, '2026-08-08T12:00:00Z');
            $this->review($previousCard, CardReviewRating::Hard, '2026-07-26T04:00:00Z');
            $this->review($excludedCard, CardReviewRating::Again, '2026-08-09T04:00:00Z');
            $this->review($otherCard, CardReviewRating::Again, '2026-08-03T12:00:00Z');
            $deletedCard->delete();
            $deletedDeckCard->deck()->firstOrFail()->delete();

            $this->getJson('/api/study/weekly-recap?timezone=America%2FNew_York&weekStartsOn=1')
                ->assertOk()
                ->assertJsonPath('week.totalMs', 3_600_000)
                ->assertJsonPath('week.activeDays', 1)
                ->assertJsonPath('week.bestDay.date', '2026-08-03')
                ->assertJsonPath('week.categories.review', 3_600_000)
                ->assertJsonPath('week.categories.conversation', 0)
                ->assertJsonPath('week.reviewCount', 3)
                ->assertJsonPath('week.recallRate', 0.667)
                ->assertJsonPath('week.newCardsIntroduced', 3)
                ->assertJsonPath('previousWeek.totalMs', 1_800_000)
                ->assertJsonPath('previousWeek.activeDays', 1)
                ->assertJsonPath('previousWeek.reviewCount', 1)
                ->assertJsonPath('previousWeek.recallRate', 1)
                ->assertJsonPath('previousWeek.newCardsIntroduced', 1);
        } finally {
            $this->travelBack();
        }
    }

    public function test_it_conserves_duration_across_midnight_and_dst_and_breaks_best_day_ties_earliest(): void
    {
        $user = $this->signIn();
        $this->travelTo(CarbonImmutable::parse('2026-03-16T12:00:00Z'));
        $originalProcessTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('Asia/Tokyo');
            // 23:30 EST Saturday through 03:30 EDT Sunday is three real hours.
            $this->createActivitySession($user, 'listen', '2026-03-08T04:30:00Z', '2026-03-08T07:30:00Z', 10_800_000);
            $this->createActivitySession($user, 'review', '2026-03-09T12:00:00Z', '2026-03-09T15:00:00Z', 10_800_000);
            $this->createActivitySession($user, 'review', '2026-03-10T12:00:00Z', '2026-03-10T15:00:00Z', 10_800_000);
            $this->createActivitySession($user, 'review', '2026-03-12T03:30:00Z', '2026-03-12T04:30:00Z', 3_600_000);

            $this->getJson('/api/study/weekly-recap?timezone=America%2FNew_York&weekStartsOn=1')
                ->assertOk()
                ->assertJsonPath('week.startsAt', '2026-03-08T05:00:00.000000Z')
                ->assertJsonPath('week.endsAt', '2026-03-15T04:00:00.000000Z')
                ->assertJsonPath('week.totalMs', 34_200_000)
                ->assertJsonPath('week.categories.listen', 9_000_000)
                ->assertJsonPath('week.categories.review', 25_200_000)
                ->assertJsonPath('week.activeDays', 5)
                ->assertJsonPath('week.bestDay.date', '2026-03-09')
                ->assertJsonPath('week.bestDay.totalMs', 10_800_000)
                ->assertJsonPath('previousWeek.totalMs', 1_800_000)
                ->assertJsonPath('previousWeek.activeDays', 1);
        } finally {
            date_default_timezone_set($originalProcessTimezone);
            $this->travelBack();
        }
    }

    public function test_existing_analytics_does_not_front_load_a_future_ended_rolling_session(): void
    {
        $user = $this->signIn();
        $this->travelTo(CarbonImmutable::parse('2026-08-12T16:00:00Z'));

        try {
            $this->createActivitySession($user, 'review', '2026-08-12T14:00:00Z', '2026-08-12T18:00:00Z', 14_400_000);
            $this->getJson('/api/study/activity-analytics?timezone=UTC&weekStartsOn=1')
                ->assertOk()
                ->assertJsonPath('ranges.0.totalMs', 7_200_000)
                ->assertJsonPath('ranges.0.categories.review', 7_200_000);
        } finally {
            $this->travelBack();
        }
    }

    public function test_recap_queries_are_owned_and_bounded_to_two_weeks(): void
    {
        $user = User::factory()->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(BuildPersonalWeeklyRecapAction::class)->handle(
                $user->id,
                new DateTimeZone('UTC'),
                1,
                CarbonImmutable::parse('2026-08-12T16:00:00Z'),
            );
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $selects = array_values(array_filter($queries, static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')));
        $this->assertCount(3, $selects);
        foreach ($selects as $query) {
            $sql = strtolower($query['query']);
            $this->assertStringContainsString('user_id', $sql);
            $this->assertStringContainsString('<', $sql);
            $this->assertGreaterThanOrEqual(2, substr_count($sql, '<') + substr_count($sql, '>'));
        }
    }

    public function test_direct_callers_get_the_same_week_start_boundary_contract(): void
    {
        $action = app(BuildPersonalWeeklyRecapAction::class);
        foreach ([1, 7] as $valid) {
            $this->assertArrayHasKey('week', $action->handle(
                User::factory()->create()->id,
                new DateTimeZone('UTC'),
                $valid,
                CarbonImmutable::parse('2026-08-12T16:00:00Z'),
            ));
        }
        foreach ([0, 8] as $invalid) {
            try {
                $action->handle(1, new DateTimeZone('UTC'), $invalid);
                $this->fail('Invalid first weekday was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('The first weekday must be between 1 and 7.', $e->getMessage());
            }
        }
    }

    private function createActivitySession(User $user, string $category, string $start, string $end, int $durationMs): void
    {
        StudyActivitySession::query()->forceCreate([
            'user_id' => $user->id,
            'client_session_id' => 'recap-'.Str::ulid(),
            'category' => $category,
            'activity' => $category === 'conversation' ? 'conversation' : 'card_review',
            'source' => 'automatic',
            'started_at' => $start,
            'ended_at' => $end,
            'duration_ms' => $durationMs,
        ]);
    }

    private function review(Card $card, CardReviewRating $rating, string $reviewedAt): void
    {
        CardReviewEvent::factory()->for($card)->create([
            'rating' => $rating,
            'reviewed_at' => $reviewedAt,
        ]);
    }
}
