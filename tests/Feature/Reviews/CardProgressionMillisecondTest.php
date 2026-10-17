<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardProgressionMillisecondTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_fractional_reviews_unlock_the_next_stage(): void
    {
        [$first, $next] = $this->linkedCards();
        $this->review($first, 'good', '2026-10-17T12:00:01.100Z');
        $this->assertSame('locked', $next->refresh()->variant_status);
        $this->review($first, 'good', '2026-10-17T12:00:01.200Z');
        $this->assertSame('available', $next->refresh()->variant_status);
    }

    public function test_a_fractional_failure_resets_the_retrieval_boundary(): void
    {
        [$first, $next] = $this->linkedCards();
        $this->review($first, 'good', '2026-10-17T12:00:01.100Z');
        $this->review($first, 'again', '2026-10-17T12:00:01.200Z');
        $this->review($first, 'good', '2026-10-17T12:00:01.300Z');
        $this->assertSame('locked', $next->refresh()->variant_status);
        $this->review($first, 'good', '2026-10-17T12:00:01.400Z');
        $this->assertSame('available', $next->refresh()->variant_status);
    }

    /** @return array{Card, Card} */
    private function linkedCards(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $first = Card::factory()->for($deck)->create();
        $next = Card::factory()->for($deck)->create();
        $this->putJson("/api/cards/{$first->id}/learning-path/successor", [
            'successor_card_id' => $next->id,
            'unlock_requirement' => 'successful_retrieval',
        ])->assertOk();

        return [$first, $next];
    }

    private function review(Card $card, string $rating, string $at): void
    {
        $this->postJson('/api/card-review-events', [
            'id' => strtolower((string) str()->ulid()),
            'card_id' => $card->id,
            'rating' => $rating,
            'reviewed_at' => $at,
        ])->assertCreated();
    }
}
