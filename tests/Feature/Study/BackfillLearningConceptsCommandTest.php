<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillLearningConceptsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_is_dry_runnable_resumable_and_idempotent(): void
    {
        $deck = Deck::factory()->create();
        $firstId = strtolower((string) Str::ulid());
        $secondId = strtolower((string) Str::ulid());
        $first = Card::factory()->for($deck)->create([
            'id' => $firstId,
            'front_text' => '会社',
            'back_text' => 'company',
            'answer_json' => ['expression' => '会社'],
        ]);
        $second = Card::factory()->for($deck)->create([
            'id' => $secondId,
            'front_text' => '会社があります。',
            'back_text' => 'There is a company.',
            'answer_json' => ['expression' => '会社', 'sentenceJp' => '会社があります。'],
        ]);

        $this->artisan('learning-concepts:backfill', ['--dry-run' => true, '--chunk' => 1])
            ->expectsOutputToContain('Dry run complete: 2 cards')
            ->assertSuccessful();
        $this->assertDatabaseCount('card_learning_concepts', 0);

        $this->artisan('learning-concepts:backfill', ['--after' => strtoupper($first->id), '--chunk' => 1])
            ->expectsOutputToContain('Backfill complete: 1 cards')
            ->assertSuccessful();
        $this->assertDatabaseMissing('card_learning_concepts', ['card_id' => $first->id]);
        $this->assertDatabaseHas('card_learning_concepts', [
            'card_id' => $second->id,
            'concept_id' => 'n5-vocab-1198550-2120ff50',
            'match_source' => 'backfill',
        ]);

        $this->artisan('learning-concepts:backfill')->assertSuccessful();
        $linkCount = $second->learningConcepts()->count();
        $this->artisan('learning-concepts:backfill')->assertSuccessful();
        $this->assertSame($linkCount, $second->learningConcepts()->count());
    }
}
