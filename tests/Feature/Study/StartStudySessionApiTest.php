<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Study\Support\StudySessionStartRateLimiter;
use App\Models\User;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class StartStudySessionApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_start_requires_authentication(): void
    {
        $this->postJson('/api/study/session/start')->assertUnauthorized();
    }

    public function test_start_returns_overview_and_ready_cards_without_trusting_client_limits(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 2,
        ]);
        $firstNewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondNewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 3,
        ]);

        $response = $this->postJson('/api/study/session/start', [
            'limit' => 999,
            'time_zone' => 'America/New_York',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.overview.new_cards_per_day', 2)
            ->assertJsonPath('data.overview.new_cards_available_today', 2)
            ->assertJsonPath('data.cards.0.id', $firstNewCard->id)
            ->assertJsonPath('data.cards.1.id', $secondNewCard->id)
            ->assertJsonCount(2, 'data.cards');
    }

    public function test_start_ignores_legacy_null_position_cards_when_filling_new_slots(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 2,
        ]);
        $legacyNullPositionCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => null,
        ]);
        $firstPositionedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondPositionedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $this->postJson('/api/study/session/start')
            ->assertOk()
            ->assertJsonPath('data.overview.new_count', 2)
            ->assertJsonPath('data.overview.new_cards_available_today', 2)
            ->assertJsonPath('data.cards.0.id', $firstPositionedCard->id)
            ->assertJsonPath('data.cards.1.id', $secondPositionedCard->id)
            ->assertJsonMissing(['id' => $legacyNullPositionCard->id])
            ->assertJsonCount(2, 'data.cards');
    }

    public function test_start_is_rate_limited_by_user_without_throttling_overview_reads(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        $this->withStudySessionStartRateLimitOverride(
            [$user->id, $otherUser->id],
            function () use ($otherUser, $user): void {
                for ($attempt = 0; $attempt < 2; $attempt++) {
                    $this
                        ->postJson('/api/study/session/start', ['time_zone' => 'America/New_York'])
                        ->assertOk()
                        ->assertJsonPath('data.overview.total_cards', 0)
                        ->assertJsonCount(0, 'data.cards');
                }

                $this->signIn($otherUser);

                $this
                    ->postJson('/api/study/session/start')
                    ->assertOk()
                    ->assertJsonPath('data.overview.total_cards', 0);

                $this->signIn($user);

                $this
                    ->postJson('/api/study/session/start')
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->getJson('/api/study/overview')
                    ->assertOk()
                    ->assertJsonPath('data.total_cards', 0);
            },
        );
    }

    public function test_start_filters_ready_cards_by_deck_id(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $targetDeckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $this->postJson('/api/study/session/start', [
            'deck_id' => $deck->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.overview.new_count', 1)
            ->assertJsonPath('data.overview.total_cards', 1)
            ->assertJsonPath('data.cards.0.id', $targetDeckCard->id)
            ->assertJsonCount(1, 'data.cards');
    }

    public function test_start_filters_ready_cards_by_course_id(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeckInCourse = $this->deckFor($user, ['course_id' => $course->id]);
        $outsideCourseDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $firstCourseCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCourseCard = $this->cardWithStudyStatus($otherDeckInCourse, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $this->cardWithStudyStatus($outsideCourseDeck, CardStudyStatus::New, [
            'new_queue_position' => 3,
        ]);

        $this->postJson('/api/study/session/start', [
            'courseId' => $course->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.overview.new_count', 2)
            ->assertJsonPath('data.overview.total_cards', 2)
            ->assertJsonPath('data.cards.0.id', $firstCourseCard->id)
            ->assertJsonPath('data.cards.1.id', $secondCourseCard->id)
            ->assertJsonCount(2, 'data.cards');
    }

    public function test_start_normalizes_deck_id_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);

        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $targetDeckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $this->postJson('/api/study/session/start', [
            'deck_id' => '  '.strtoupper($deck->id).'  ',
        ])
            ->assertOk()
            ->assertJsonPath('data.overview.new_count', 1)
            ->assertJsonPath('data.cards.0.id', $targetDeckCard->id)
            ->assertJsonCount(1, 'data.cards');
    }

    public function test_start_normalizes_scope_filter_aliases_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);

        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeckInCourse = $this->deckFor($user, ['course_id' => $course->id]);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $targetDeckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeckInCourse, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $this->postJson('/api/study/session/start', [
            'course_id' => '  '.strtoupper($course->id).'  ',
            'deckId' => '  '.strtoupper($deck->id).'  ',
        ])
            ->assertOk()
            ->assertJsonPath('data.overview.new_count', 1)
            ->assertJsonPath('data.cards.0.id', $targetDeckCard->id)
            ->assertJsonCount(1, 'data.cards');
    }

    public function test_start_returns_empty_session_when_course_and_deck_filters_do_not_match(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);

        $this->postJson('/api/study/session/start', [
            'courseId' => $course->id,
            'deckId' => $deck->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.overview.new_count', 0)
            ->assertJsonPath('data.overview.total_cards', 0)
            ->assertJsonCount(0, 'data.cards');
    }

    public function test_start_returns_empty_session_for_another_users_deck_id(): void
    {
        $user = $this->signIn();
        StudySettings::factory()->for($user)->create();
        $otherDeck = $this->deckFor(User::factory()->create());
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'due_at' => Carbon::parse('2026-06-04T11:00:00Z'),
        ]);

        $this->postJson('/api/study/session/start', [
            'deck_id' => $otherDeck->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.overview.due_count', 0)
            ->assertJsonPath('data.overview.total_cards', 0)
            ->assertJsonCount(0, 'data.cards');
    }

    public function test_start_returns_empty_session_for_another_users_course_id(): void
    {
        $user = $this->signIn();
        StudySettings::factory()->for($user)->create();
        $otherUser = User::factory()->create();
        $otherCourse = Course::factory()->for($otherUser)->create();
        $otherDeck = $this->deckFor($otherUser, ['course_id' => $otherCourse->id]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'due_at' => Carbon::parse('2026-06-04T11:00:00Z'),
        ]);

        $this->postJson('/api/study/session/start', [
            'courseId' => $otherCourse->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.overview.due_count', 0)
            ->assertJsonPath('data.overview.total_cards', 0)
            ->assertJsonCount(0, 'data.cards');
    }

    public function test_start_validates_time_zone_without_coercing_malformed_values(): void
    {
        $this->signIn();

        $this->postJson('/api/study/session/start', [
            'time_zone' => 'Not/A_Zone',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['time_zone']);

        $this->postJson('/api/study/session/start', [
            'time_zone' => ['America/New_York'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['time_zone']);
    }

    public function test_start_rejects_malformed_deck_id_filters(): void
    {
        $this->signIn();

        $this->postJson('/api/study/session/start', [
            'deck_id' => 'not-a-ulid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->postJson('/api/study/session/start', [
            'deck_id' => ['01J00000000000000000000000'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->postJson('/api/study/session/start', [
            'deckId' => 'not-a-ulid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deckId']);

        $this->postJson('/api/study/session/start', [
            'courseId' => 'not-a-ulid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['courseId']);

        $this->postJson('/api/study/session/start', [
            'course_id' => 'not-a-ulid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);

        $this->postJson('/api/study/session/start', [
            'course_id' => ['01J00000000000000000000000'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_start_rejects_conflicting_camel_and_legacy_scope_filters(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);

        $this->postJson('/api/study/session/start', [
            'courseId' => $course->id,
            'course_id' => $otherCourse->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['courseId']);

        $this->postJson('/api/study/session/start', [
            'deckId' => $deck->id,
            'deck_id' => $otherDeck->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deckId']);
    }

    public function test_start_rejects_blank_deck_id_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        $this->signIn();

        $this->postJson('/api/study/session/start', [
            'deck_id' => '   ',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->postJson('/api/study/session/start', [
            'deckId' => '   ',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deckId']);

        $this->postJson('/api/study/session/start', [
            'courseId' => '   ',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['courseId']);

        $this->postJson('/api/study/session/start', [
            'course_id' => '   ',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_start_uses_the_requested_time_zone_for_daily_new_card_allowance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T03:00:00Z'));

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 2,
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
                'introduced_at' => Carbon::parse('2026-06-03T05:00:00Z'),
                'due_at' => Carbon::parse('2026-06-05T00:00:00Z'),
            ]);
            $newCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 1,
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 2,
            ]);

            $this->postJson('/api/study/session/start', [
                'time_zone' => 'America/New_York',
            ])
                ->assertOk()
                ->assertJsonPath('data.overview.new_cards_introduced_today', 1)
                ->assertJsonPath('data.overview.new_cards_available_today', 1)
                ->assertJsonPath('data.cards.0.id', $newCard->id)
                ->assertJsonCount(1, 'data.cards');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_start_returns_ready_failed_cards_before_new_cards(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 20,
            ]);
            $readyFailedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Relearning, [
                'due_at' => Carbon::parse('2026-06-04T11:50:00Z'),
                'failed_at' => Carbon::parse('2026-06-04T11:00:00Z'),
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 1,
            ]);

            $this->postJson('/api/study/session/start')
                ->assertOk()
                ->assertJsonPath('data.overview.due_count', 0)
                ->assertJsonPath('data.overview.failed_count', 1)
                ->assertJsonPath('data.overview.new_cards_available_today', 0)
                ->assertJsonMissingPath('data.overview.failed_due_count')
                ->assertJsonPath('data.cards.0.id', $readyFailedCard->id)
                ->assertJsonCount(1, 'data.cards');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @param  list<int|string>  $userIdsToClear
     */
    private function withStudySessionStartRateLimitOverride(
        array $userIdsToClear,
        Closure $callback,
        int $perMinute = 2,
        string $clientIp = '127.0.0.1',
    ): void {
        $limiter = new StudySessionStartRateLimiter;
        $testBucket = 'test-'.Str::ulid();
        $testRateLimitKey = fn (int|string|null $userId, ?string $ip): string => $testBucket.'|'.$limiter->keyFor($userId, $ip);
        $previousServerVariables = $this->serverVariables;

        try {
            $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

            RateLimiter::for(StudySessionStartRateLimiter::NAME, function (Request $request) use ($perMinute, $testRateLimitKey): Limit {
                return Limit::perMinute($perMinute)->by($testRateLimitKey(
                    $request->user()?->getAuthIdentifier(),
                    $request->ip(),
                ));
            });

            $callback();
        } finally {
            foreach ($userIdsToClear as $userId) {
                RateLimiter::clear($testRateLimitKey($userId, $clientIp));
            }

            RateLimiter::for(StudySessionStartRateLimiter::NAME, fn (Request $request): Limit => $limiter->limit($request));
            $this->withServerVariables($previousServerVariables);
        }
    }
}
