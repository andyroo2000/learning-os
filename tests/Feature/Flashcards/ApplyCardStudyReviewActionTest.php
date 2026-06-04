<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\ApplyCardStudyReviewAction;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ApplyCardStudyReviewActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_applies_hard_reviews_to_new_cards_as_learning(): void
    {
        $card = Card::factory()->create([
            'new_queue_position' => 3,
        ]);
        $reviewedAt = Carbon::parse('2026-05-27T09:15:00Z');

        $updated = app(ApplyCardStudyReviewAction::class)->handle(
            card: $card,
            rating: CardReviewRating::Hard,
            reviewedAt: $reviewedAt,
        );

        $card->refresh();

        $this->assertTrue($updated);
        $this->assertSame(CardStudyStatus::Learning, $card->study_status);
        $this->assertNull($card->new_queue_position);
        $this->assertSame($reviewedAt->toJSON(), $card->introduced_at?->toJSON());
        $this->assertSame($reviewedAt->copy()->addDay()->toJSON(), $card->due_at?->toJSON());
        $this->assertNull($card->failed_at);
        $this->assertSame($reviewedAt->toJSON(), $card->last_reviewed_at?->toJSON());
        $this->assertDatabaseHas('sync_feed_entries', [
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => $card->id,
            'operation' => 'update',
        ]);
    }

    public function test_it_keeps_hard_reviews_on_review_cards_in_review(): void
    {
        $card = Card::factory()->create([
            'study_status' => CardStudyStatus::Review,
            'introduced_at' => '2026-05-20T09:15:00Z',
        ]);
        $reviewedAt = Carbon::parse('2026-05-27T09:15:00Z');

        app(ApplyCardStudyReviewAction::class)->handle(
            card: $card,
            rating: CardReviewRating::Hard,
            reviewedAt: $reviewedAt,
        );

        $card->refresh();

        $this->assertSame(CardStudyStatus::Review, $card->study_status);
        $this->assertSame('2026-05-20T09:15:00.000000Z', $card->introduced_at?->toJSON());
        $this->assertSame($reviewedAt->copy()->addDay()->toJSON(), $card->due_at?->toJSON());
    }

    public function test_it_skips_reviews_older_than_the_current_card_state(): void
    {
        $card = Card::factory()->create([
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-10T09:15:00Z',
            'last_reviewed_at' => '2026-05-28T09:15:00Z',
        ]);

        $updated = app(ApplyCardStudyReviewAction::class)->handle(
            card: $card,
            rating: CardReviewRating::Again,
            reviewedAt: Carbon::parse('2026-05-27T09:15:00Z'),
        );

        $card->refresh();

        $this->assertFalse($updated);
        $this->assertSame(CardStudyStatus::Review, $card->study_status);
        $this->assertSame('2026-06-10T09:15:00.000000Z', $card->due_at?->toJSON());
        $this->assertNull($card->failed_at);
        $this->assertSame('2026-05-28T09:15:00.000000Z', $card->last_reviewed_at?->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
