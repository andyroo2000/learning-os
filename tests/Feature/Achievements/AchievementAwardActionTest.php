<?php

namespace Tests\Feature\Achievements;

use App\Domain\Achievements\Actions\CalculateAchievementMetricsAction;
use App\Domain\Achievements\Actions\ReconcileAchievementAwardsAction;
use App\Domain\Achievements\Actions\ResolveAchievementEarnedAtAction;
use App\Domain\Achievements\Models\AchievementAward;
use App\Domain\Achievements\Models\AchievementCardProjection;
use App\Domain\Achievements\Models\AchievementProgressProjection;
use App\Domain\Achievements\Models\AchievementStudySessionProjection;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Enums\StudyActivityOrigin;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Models\StudyActivitySession;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class AchievementAwardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_achievement_projection_state_is_process_owned(): void
    {
        foreach ([
            new AchievementProgressProjection,
            new AchievementCardProjection,
            new AchievementStudySessionProjection,
        ] as $projection) {
            $this->assertThrows(
                fn () => $projection->fill([
                    'user_id' => 42,
                    'metric_values' => ['reviews.count' => 1],
                    'maximum_stability' => 365,
                    'conversation_ms' => 3_600_000,
                    'needs_rebuild' => true,
                ]),
                MassAssignmentException::class,
            );
        }
    }

    public function test_direct_actions_reject_invalid_user_metric_and_threshold_values(): void
    {
        $reconcile = app(ReconcileAchievementAwardsAction::class);
        $resolver = app(ResolveAchievementEarnedAtAction::class);
        $metrics = app(CalculateAchievementMetricsAction::class);

        $this->assertThrows(
            fn () => $metrics->handle(0),
            InvalidArgumentException::class,
            'Achievement metric user ID must be positive.',
        );
        $this->assertThrows(
            fn () => $reconcile->handle(0, []),
            InvalidArgumentException::class,
            'Achievement award user ID must be positive.',
        );
        $this->assertThrows(
            fn () => $reconcile->handle(1, ['reviews.count' => -1]),
            InvalidArgumentException::class,
            'Achievement metric values must be non-negative.',
        );
        $this->assertThrows(
            fn () => $resolver->handle(1, 'reviews.count', 0),
            InvalidArgumentException::class,
            'Achievement award threshold must be positive.',
        );
        $this->assertThrows(
            fn () => $resolver->handle(1, 'unknown.metric', 1),
            InvalidArgumentException::class,
            'Unsupported achievement metric unknown.metric.',
        );
    }

    public function test_server_owned_award_fields_are_not_mass_assignable(): void
    {
        $user = User::factory()->create();

        $this->assertThrows(
            fn () => (new AchievementAward)->fill([
                'user_id' => $user->id,
                'achievement_id' => 'card-muncher.first-nibble',
                'earned_at' => now(),
            ]),
            MassAssignmentException::class,
        );
    }

    public function test_review_achievement_dates_share_one_timeline_scan_across_tiers(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create();
        CardReviewEvent::factory()->for($card, 'card')->count(30)->create([
            'rating' => CardReviewRating::Good,
        ]);
        $resolver = app(ResolveAchievementEarnedAtAction::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $resolver->handle($user->id, 'reviews.old-friend.count', 1);
            $resolver->handle($user->id, 'reviews.correct-run.longest', 10);
            $resolver->handle($user->id, 'reviews.correct-run.longest', 25);
            $resolver->handle($user->id, 'cards.mastery.guru.ever.count', 1);
            $resolver->handle($user->id, 'cards.mastery.burned.ever.count', 1);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $timelineQueries = collect($queries)->filter(
            static fn (array $query): bool => str_contains($query['query'], 'card_review_events')
                && str_contains(strtolower($query['query']), 'order by'),
        );
        $this->assertCount(
            1,
            $timelineQueries,
            'Review, correct-run, and mastery award dates must reuse one chronological review query.',
        );
    }

    public function test_study_achievement_dates_share_one_session_scan_across_families_and_tiers(): void
    {
        $user = User::factory()->create();
        foreach ([StudyActivityCategory::Listen, StudyActivityCategory::Conversation] as $index => $category) {
            $endedAt = now()->addMinutes($index);
            StudyActivitySession::query()->forceCreate([
                'user_id' => $user->id,
                'client_session_id' => (string) Str::ulid(),
                'category' => $category,
                'activity' => $category === StudyActivityCategory::Listen
                    ? StudyActivityKind::DailyAudio
                    : StudyActivityKind::Conversation,
                'source' => StudyActivitySource::Automatic,
                'origin' => StudyActivityOrigin::Web,
                'name' => $category === StudyActivityCategory::Listen
                    ? CalculateAchievementMetricsAction::DAILY_AUDIO_COMPLETION_PREFIX.'Episode A'
                    : 'Conversation',
                'started_at' => $endedAt->copy()->subHour(),
                'ended_at' => $endedAt,
                'duration_ms' => 3_600_000,
                'audio_playback_ms' => $category === StudyActivityCategory::Listen ? 3_600_000 : null,
            ]);
        }
        $resolver = app(ResolveAchievementEarnedAtAction::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $resolver->handle($user->id, 'study.conversation.hours', 1);
            $resolver->handle($user->id, 'study.conversation.hours', 2);
            $resolver->handle($user->id, 'study.listening.hours', 1);
            $resolver->handle($user->id, 'study.listening.hours', 2);
            $resolver->handle($user->id, 'study.double-feature.days', 1);
            $resolver->handle($user->id, 'study.daily-audio.repeat-days', 1);
            $resolver->handle($user->id, 'study.daily-audio.repeat-days', 3);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $sessionQueries = collect($queries)->filter(
            static fn (array $query): bool => str_contains($query['query'], 'study_activity_sessions'),
        );
        $this->assertCount(
            1,
            $sessionQueries,
            'Every study-time achievement family and tier must reuse one chronological session query.',
        );
    }
}
