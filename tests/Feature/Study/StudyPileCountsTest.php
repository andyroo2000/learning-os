<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Actions\SaveStudyPileAction;
use App\Domain\Study\Actions\SaveStudyPileChapterAction;
use App\Domain\Study\Services\StudyPileCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyPileCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_learning_evidence_counts_without_backfilling_or_rescheduling(): void
    {
        $user = $this->signIn();
        $deck = Deck::factory()->for($user)->create();
        $pile = app(SaveStudyPileAction::class)->handle($user->id, (string) Str::ulid(), ['title' => 'N4']);
        $chapter = app(SaveStudyPileChapterAction::class)->handle($user->id, $pile->id, (string) Str::ulid(), ['title' => 'Chapter 1']);
        $source = ['book_pile_id' => $pile->id, 'book_chapter_id' => $chapter->id];
        $cards = $this->learningStates($deck, $source);
        $before = $cards->map(fn (Card $card) => $card->fresh()->getRawOriginal())->all();

        $this->getJson('/api/study/piles')->assertOk()
            ->assertJsonPath('piles.0.introduced_count', 7)
            ->assertJsonPath('piles.0.chapters.0.introduced_count', 7)
            ->assertJsonPath('piles.0.new_count', 1)
            ->assertJsonPath('piles.0.ready_count', 0);

        $this->assertSame($before, $cards->map(fn (Card $card) => $card->fresh()->getRawOriginal())->all());
    }

    public function test_uncategorized_counts_keep_ownership_and_soft_delete_filters(): void
    {
        $user = $this->signIn();
        $deck = Deck::factory()->for($user)->create();
        $this->learningStates($deck);
        Card::factory()->create(['study_status' => 'review']);
        Card::factory()->for($deck)->create(['study_status' => 'review'])->delete();
        $deletedDeck = Deck::factory()->for($user)->create();
        Card::factory()->for($deletedDeck)->create(['study_status' => 'review']);
        $deletedDeck->delete();
        $counts = app(StudyPileCounts::class)->forUser($user->id);
        $this->assertSame(['new_count' => 1, 'ready_count' => 1, 'introduced_count' => 7], $counts['uncategorized']);
    }

    private function learningStates(Deck $deck, array $source = [])
    {
        $states = [
            ['study_status' => 'learning'],
            ['study_status' => 'review'],
            ['study_status' => 'relearning'],
            ['study_status' => 'review', 'introduced_at' => now()->subDays(10)],
            ['study_status' => 'suspended', 'source_reps' => 5],
            ['study_status' => 'buried', 'last_reviewed_at' => now()->subDay()],
            ['study_status' => 'suspended', 'introduced_at' => now()->subDay()],
            ['study_status' => 'suspended'],
            ['study_status' => 'buried'],
            ['study_status' => 'new', 'new_queue_position' => 1, 'source_reps' => 10],
        ];

        return collect($states)->map(fn (array $state) => Card::factory()->for($deck)->create([
            'introduced_at' => null, 'source_reps' => 0, 'last_reviewed_at' => null,
            'due_at' => now()->addDays(7), ...$source, ...$state,
        ]));
    }
}
