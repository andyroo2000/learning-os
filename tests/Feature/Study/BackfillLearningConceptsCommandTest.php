<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Japanese\Contracts\JapaneseTokenizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillLearningConceptsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_is_dry_runnable_resumable_and_idempotent(): void
    {
        $this->app->instance(JapaneseTokenizer::class, new class implements JapaneseTokenizer
        {
            public function tokenize(array $texts): array
            {
                return array_fill(0, count($texts), []);
            }

            public function hadFailure(): bool
            {
                return false;
            }
        });
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

    public function test_backfill_fails_before_changing_cards_when_tokenization_is_unavailable(): void
    {
        config()->set('services.mecab.binary', '/definitely-missing/convolab-mecab');
        $deck = Deck::factory()->create();
        Card::factory()->for($deck)->create([
            'front_text' => '会社',
            'back_text' => 'company',
        ]);

        $this->artisan('learning-concepts:backfill')
            ->expectsOutputToContain('Japanese tokenization is unavailable. No cards were changed')
            ->assertFailed();

        $this->assertDatabaseCount('card_learning_concepts', 0);
    }

    public function test_mid_run_tokenizer_failure_does_not_persist_a_degraded_card(): void
    {
        $this->app->instance(JapaneseTokenizer::class, new class implements JapaneseTokenizer
        {
            private int $calls = 0;

            private bool $failed = false;

            public function tokenize(array $texts): array
            {
                $this->calls++;

                if ($this->calls > 1) {
                    $this->failed = true;
                }

                return array_fill(0, count($texts), []);
            }

            public function hadFailure(): bool
            {
                return $this->failed;
            }
        });
        $deck = Deck::factory()->create();
        Card::factory()->for($deck)->create([
            'front_text' => '会社',
            'back_text' => 'company',
        ]);

        $this->artisan('learning-concepts:backfill')
            ->expectsOutputToContain('Japanese tokenization failed during the backfill')
            ->assertFailed();

        $this->assertDatabaseCount('card_learning_concepts', 0);
    }
}
