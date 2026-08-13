<?php

namespace Tests\Feature\Reviews;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CardLastReviewedAtPrecisionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_uses_each_cards_latest_canonical_event_without_rewriting_unrelated_state(): void
    {
        $backfilled = Card::factory()->create(['last_reviewed_at' => '2026-05-27 09:15:00']);
        $equalTimestampTie = Card::factory()->create(['last_reviewed_at' => '2026-05-27 10:15:00']);
        $otherOwnerCard = Card::factory()->create(['last_reviewed_at' => '2026-05-27 11:15:00']);
        $nullCard = Card::factory()->create(['last_reviewed_at' => null]);
        $noEventCard = Card::factory()->create(['last_reviewed_at' => '2026-05-27 12:15:00']);
        $divergentCard = Card::factory()->create(['last_reviewed_at' => '2026-05-27 13:16:00']);

        DB::table('card_review_events')->insert([
            $this->event('01k1j8j9m0e4k7r2y8p5w6q3aa', $backfilled->id, '2026-05-27 09:15:00.123'),
            $this->event('01k1j8j9m0e4k7r2y8p5w6q3ab', $backfilled->id, '2026-05-27 09:15:00.987'),
            $this->event('01k1j8j9m0e4k7r2y8p5w6q3ac', $equalTimestampTie->id, '2026-05-27 10:15:00.777'),
            $this->event('01K1J8J9M0E4K7R2Y8P5W6Q3AD', $equalTimestampTie->id, '2026-05-27 10:15:00.777'),
            $this->event('01k1j8j9m0e4k7r2y8p5w6q3ae', $otherOwnerCard->id, '2026-05-27 11:15:00.456'),
            $this->event('01k1j8j9m0e4k7r2y8p5w6q3af', $nullCard->id, '2026-05-27 14:15:00.789'),
            $this->event('01k1j8j9m0e4k7r2y8p5w6q3ag', $divergentCard->id, '2026-05-27 13:15:00.999'),
        ]);

        $this->migration()->up();

        $this->assertSame('2026-05-27T09:15:00.987000Z', $backfilled->refresh()->last_reviewed_at?->toJSON());
        $this->assertSame('2026-05-27T10:15:00.777000Z', $equalTimestampTie->refresh()->last_reviewed_at?->toJSON());
        $this->assertSame('2026-05-27T11:15:00.456000Z', $otherOwnerCard->refresh()->last_reviewed_at?->toJSON());
        $this->assertNull($nullCard->refresh()->last_reviewed_at);
        $this->assertSame('2026-05-27T12:15:00.000000Z', $noEventCard->refresh()->last_reviewed_at?->toJSON());
        $this->assertSame('2026-05-27T13:16:00.000000Z', $divergentCard->refresh()->last_reviewed_at?->toJSON());
    }

    public function test_sqlite_rollback_is_schema_safe_and_does_not_invent_a_data_reversal(): void
    {
        $card = Card::factory()->create(['last_reviewed_at' => '2026-05-27 09:15:00']);
        DB::table('card_review_events')->insert(
            $this->event('01k1j8j9m0e4k7r2y8p5w6q3aa', $card->id, '2026-05-27 09:15:00.123'),
        );

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertSame('2026-05-27T09:15:00.123000Z', $card->refresh()->last_reviewed_at?->toJSON());
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('cards', 'last_reviewed_at'));
    }

    /** @return array<string, mixed> */
    private function event(string $id, string $cardId, string $reviewedAt): array
    {
        return [
            'id' => $id,
            'card_id' => $cardId,
            'rating' => 'good',
            'reviewed_at' => $reviewedAt,
            'created_at' => '2026-05-27 15:00:00',
            'updated_at' => '2026-05-27 15:00:00',
        ];
    }

    private function migration(): object
    {
        return require LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_08_13_010000_preserve_card_last_reviewed_at_precision.php';
    }
}
