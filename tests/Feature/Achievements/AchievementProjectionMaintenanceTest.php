<?php

namespace Tests\Feature\Achievements;

use App\Domain\Achievements\Actions\GetAchievementProgressAction;
use App\Domain\Achievements\Models\AchievementAward;
use App\Domain\Achievements\Models\AchievementCardProjection;
use App\Domain\Achievements\Models\AchievementProgressProjection;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\DeleteStudyActivitySessionAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Achievements\BuildsAchievementStudySessions;
use Tests\TestCase;

class AchievementProjectionMaintenanceTest extends TestCase
{
    use BuildsAchievementStudySessions;
    use RefreshDatabase;

    public function test_it_projects_new_reviews_and_persists_the_exact_threshold_timestamp(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create();

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();

        $firstReviewAt = now()->startOfSecond();
        CardReviewEvent::factory()->for($card, 'card')->count(100)->sequence(
            fn ($sequence): array => [
                'rating' => CardReviewRating::Good,
                'reviewed_at' => $firstReviewAt->copy()->addSeconds($sequence->index),
            ],
        )->create();
        $thresholdReviewAt = $firstReviewAt->copy()->addSeconds(99);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($user)
            ->postJson('/api/achievements/evaluate')
            ->assertOk();

        $this->assertSame(100, $response->json('metricValues')[GetAchievementProgressAction::REVIEW_METRIC]);
        $this->assertSame(
            $thresholdReviewAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            collect($response->json('awards'))->firstWhere('id', 'card-muncher.first-nibble')['earnedAt'],
        );

        $projection = AchievementProgressProjection::query()->findOrFail($user->id);
        $this->assertSame(100, $projection->metric_values[GetAchievementProgressAction::REVIEW_METRIC]);
        $this->assertSame(
            $thresholdReviewAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            $projection->threshold_reached_at[GetAchievementProgressAction::REVIEW_METRIC]['100'],
        );
        $this->assertCount(1, collect($queries)->filter(
            static fn (string $sql): bool => str_starts_with($sql, 'insert ')
                && str_contains($sql, 'achievement_card_projections'),
        ), implode("\n", $queries));
    }

    public function test_late_cross_card_review_rebuilds_global_correct_run_chronology(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $earlierCard = Card::factory()->for($deck)->create();
        $laterCard = Card::factory()->for($deck)->create();
        $laterReviewAt = now()->startOfSecond();
        $laterEvent = CardReviewEvent::factory()->for($laterCard, 'card')->create([
            'rating' => CardReviewRating::Good,
            'reviewed_at' => $laterReviewAt,
            'created_at' => $laterReviewAt,
            'updated_at' => $laterReviewAt,
        ]);

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();
        $this->assertSame(
            1,
            AchievementProgressProjection::query()->findOrFail($user->id)->current_correct_run,
        );

        CardReviewEvent::factory()->for($earlierCard, 'card')->create([
            'rating' => CardReviewRating::Again,
            'reviewed_at' => $laterReviewAt->copy()->subMinutes(30),
            'created_at' => $laterEvent->created_at->copy()->addSecond(),
            'updated_at' => $laterEvent->created_at->copy()->addSecond(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/achievements/progress')
            ->assertOk();

        $this->assertSame(
            1,
            $response->json('metricValues')[GetAchievementProgressAction::CORRECT_RUN_METRIC],
        );
        $this->assertSame(
            1,
            AchievementProgressProjection::query()->findOrFail($user->id)->current_correct_run,
        );
    }

    public function test_it_projects_new_study_time_without_rescanning_review_history(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create();
        CardReviewEvent::factory()->for($card, 'card')->create([
            'reviewed_at' => now()->subDay(),
        ]);
        $endedAt = now()->startOfSecond();

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();
        $this->conversationSession($user, 3_600_000, $endedAt);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($user)
            ->postJson('/api/achievements/evaluate')
            ->assertOk();

        $this->assertSame(1, $response->json('metricValues')[GetAchievementProgressAction::CONVERSATION_HOUR_METRIC]);
        $this->assertSame(
            $endedAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            collect($response->json('awards'))->firstWhere('id', 'roarer.first-roar')['earnedAt'],
        );
        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'card_review_events')
                && str_contains($sql, 'order by "card_review_events"."reviewed_at"')
                && ! str_contains($sql, 'card_review_events"."created_at"'),
        ), implode("\n", $queries));
    }

    public function test_a_real_review_updates_the_card_projection_without_a_second_card_pass(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create();

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();

        $reviewedAt = now()->addSecond()->startOfSecond();
        Carbon::setTestNow($reviewedAt);
        try {
            app(ReviewCardAction::class)->handle(ReviewCardData::fromInput(
                cardId: $card->id,
                rating: CardReviewRating::Good->value,
                reviewedAt: $reviewedAt,
            ));
        } finally {
            Carbon::setTestNow();
        }

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();

        $this->assertTrue(
            AchievementCardProjection::query()
                ->findOrFail($card->id)
                ->source_updated_at
                ->equalTo($card->refresh()->updated_at),
        );
        $this->assertCount(1, collect($queries)->filter(
            static fn (string $sql): bool => str_starts_with($sql, 'insert ')
                && str_contains($sql, 'achievement_card_projections'),
        ), implode("\n", $queries));
    }

    public function test_unchanged_cards_are_not_rewritten_after_projection_bootstrap(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create();

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();

        $cardProjection = AchievementCardProjection::query()->findOrFail($card->id);
        $this->assertTrue($cardProjection->source_updated_at->equalTo($card->updated_at));

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'achievement_card_projections')
                && (str_starts_with($sql, 'update ') || str_starts_with($sql, 'insert ')),
        ), implode("\n", $queries));
    }

    public function test_incremental_unreviewed_mastery_crossing_uses_created_at_in_global_order(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $firstCreatedAt = now()->subDays(60)->startOfSecond();
        $cards = Card::factory()
            ->for($deck)
            ->count(50)
            ->sequence(fn ($sequence): array => [
                'created_at' => $firstCreatedAt->copy()->addMinutes($sequence->index),
                'updated_at' => $firstCreatedAt->copy()->addMinutes($sequence->index),
            ])
            ->create([
                'study_status' => CardStudyStatus::Review,
                'scheduler_state' => ['stability' => 1],
                'last_reviewed_at' => null,
            ]);

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();

        Carbon::setTestNow(now()->addSecond());
        try {
            foreach ($cards as $card) {
                $card->forceFill(['scheduler_state' => ['stability' => 7]])->saveOrFail();
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();

        $projection = AchievementProgressProjection::query()->findOrFail($user->id);
        $this->assertSame(
            $firstCreatedAt->copy()->addMinutes(49)->utc()->format('Y-m-d\TH:i:s.v\Z'),
            $projection->threshold_reached_at[GetAchievementProgressAction::GURU_CARD_METRIC]['50'],
        );
    }

    public function test_it_batches_new_study_session_projection_writes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();

        $firstEndedAt = now()->startOfSecond();
        for ($index = 0; $index < 100; $index++) {
            $this->conversationSession(
                $user,
                60_000,
                $firstEndedAt->copy()->addMinutes($index),
            );
        }
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($user)
            ->getJson('/api/achievements/progress')
            ->assertOk();

        $this->assertSame(
            1,
            $response->json('metricValues')[GetAchievementProgressAction::CONVERSATION_HOUR_METRIC],
        );
        $this->assertCount(1, collect($queries)->filter(
            static fn (string $sql): bool => str_starts_with($sql, 'insert ')
                && str_contains($sql, 'achievement_study_session_projections'),
        ), implode("\n", $queries));
    }

    public function test_deleting_a_study_session_invalidates_and_rebuilds_the_projection(): void
    {
        $user = User::factory()->create();
        $session = $this->conversationSession($user, 3_600_000);

        $this->actingAs($user)
            ->postJson('/api/achievements/evaluate')
            ->assertOk();
        $this->assertFalse(AchievementProgressProjection::query()->findOrFail($user->id)->needs_rebuild);

        $this->assertTrue(app(DeleteStudyActivitySessionAction::class)->handle(
            $user->id,
            $session->client_session_id,
        ));
        $this->assertTrue(AchievementProgressProjection::query()->findOrFail($user->id)->needs_rebuild);

        $metrics = $this->actingAs($user)
            ->getJson('/api/achievements/progress')
            ->assertOk()
            ->json('metricValues');
        $this->assertSame(0, $metrics[GetAchievementProgressAction::CONVERSATION_HOUR_METRIC]);
        $this->assertFalse(AchievementProgressProjection::query()->findOrFail($user->id)->needs_rebuild);
    }

    public function test_editing_a_projected_study_fact_rebuilds_the_chronological_cursor(): void
    {
        $user = User::factory()->create();
        $originalEnd = now()->subDay()->startOfSecond();
        $session = $this->conversationSession($user, 3_600_000, $originalEnd);

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();
        $this->assertTrue(
            AchievementProgressProjection::query()
                ->findOrFail($user->id)
                ->latest_study_ended_at
                ->equalTo($originalEnd),
        );

        $correctedEnd = $originalEnd->copy()->subDay();
        Carbon::setTestNow(now()->addSecond());
        try {
            $session->forceFill([
                'started_at' => $correctedEnd->copy()->subHour(),
                'ended_at' => $correctedEnd,
            ])->saveOrFail();
        } finally {
            Carbon::setTestNow();
        }

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();

        $projection = AchievementProgressProjection::query()->findOrFail($user->id);
        $this->assertTrue($projection->latest_study_ended_at->equalTo($correctedEnd));
        $this->assertSame(
            1,
            $projection->metric_values[GetAchievementProgressAction::CONVERSATION_HOUR_METRIC],
        );
    }

    public function test_evaluation_backfills_and_keeps_all_awards_in_reverse_chronological_order(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $stableAt = now()->subDays(3)->startOfSecond();
        $reviewAt = now()->subDays(2)->startOfSecond();
        $conversationAt = now()->subDay()->startOfSecond();

        Card::factory()->for($deck)->count(50)->create([
            'study_status' => CardStudyStatus::Review,
            'scheduler_state' => ['stability' => 365],
            'last_reviewed_at' => $stableAt,
        ]);
        $reviewedCard = Card::factory()->for($deck)->create();
        CardReviewEvent::factory()->for($reviewedCard, 'card')->count(100)->create([
            'reviewed_at' => $reviewAt,
            'rating' => CardReviewRating::Again,
        ]);
        $this->conversationSession($user, 60 * 60_000, $conversationAt);

        $this->actingAs($user)
            ->getJson('/api/achievements/progress')
            ->assertOk()
            ->assertJsonCount(0, 'awards');

        $response = $this->actingAs($user)
            ->postJson('/api/achievements/evaluate')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(6, 'awards');

        $awardIds = array_column($response->json('awards'), 'id');
        $this->assertSame('roarer.first-roar', $awardIds[0]);
        $this->assertSame('card-muncher.first-nibble', $awardIds[1]);
        $this->assertEqualsCanonicalizing([
            'mountain-path.trailhead',
            'workshop.first-finish',
            'open-sky.first-feather',
            'archive.first-shelf',
        ], array_slice($awardIds, 2));
        $this->assertSame(
            $conversationAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            $response->json('awards.0.earnedAt'),
        );
        $this->assertSame(
            $reviewAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            $response->json('awards.1.earnedAt'),
        );
        $this->assertSame(
            $stableAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            $response->json('awards.2.earnedAt'),
        );

        $this->actingAs($user)
            ->postJson('/api/achievements/evaluate')
            ->assertOk()
            ->assertJsonCount(6, 'awards');
        $this->assertSame(6, AchievementAward::query()->where('user_id', $user->id)->count());

        CardReviewEvent::query()->where('card_id', $reviewedCard->id)->delete();
        $this->actingAs($user)
            ->getJson('/api/achievements/progress')
            ->assertOk()
            ->assertJsonCount(6, 'awards');
    }
}
