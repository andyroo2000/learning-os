<?php

namespace Tests\Feature\Achievements;

use App\Domain\Achievements\Support\AchievementReviewQuery;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AchievementReviewTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_streams_owned_archived_history_in_timestamp_and_id_order_without_hydrating_models(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create();
        $ids = collect(range(1, 3))->map(static fn (): string => strtolower((string) Str::ulid()))->sort()->values();
        $this->insertReview($card, $ids[2], '2026-10-15 18:51:58.123');
        $this->insertReview($card, $ids[0], '2026-10-15 18:51:58.234');
        $this->insertReview($card, $ids[1], '2026-10-15 18:51:58.234');
        $this->insertReview(Card::factory()->create(), strtolower((string) Str::ulid()), '2026-10-15 18:51:58.100');
        $card->delete();
        $deck->delete();

        $hydrated = 0;
        CardReviewEvent::retrieved(static function () use (&$hydrated): void {
            $hydrated++;
        });
        $timeline = AchievementReviewQuery::timeline($user->id)->values()->all();

        $this->assertSame([$ids[2], $ids[0], $ids[1]], array_column($timeline, 'id'));
        $this->assertSame(0, $hydrated);
        $this->assertSame($card->id, $timeline[0]->cardId);
        $this->assertSame(CardReviewRating::Again, $timeline[0]->rating);
        $this->assertSame(365.5, $timeline[0]->stability);
        $this->assertSame('2026-10-15 18:51:58.123', $timeline[0]->reviewedAt->format('Y-m-d H:i:s.v'));
    }

    private function insertReview(Card $card, string $id, string $reviewedAt): void
    {
        DB::table('card_review_events')->insert([
            'id' => $id,
            'card_id' => $card->id,
            'rating' => 'again',
            'reviewed_at' => $reviewedAt,
            'created_at' => '2026-10-15 18:52:00',
            'updated_at' => '2026-10-15 18:52:00',
            'scheduler_state_after' => '{"stability":365.5}',
            'card_state_before' => json_encode(['content' => str_repeat('large undo payload ', 1000)]),
        ]);
    }
}
