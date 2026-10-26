<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Actions\BuildStudyOfflineReserveAction;
use App\Domain\Study\Actions\SaveStudyPileAction;
use App\Domain\Study\Actions\SaveStudyPileChapterAction;
use App\Domain\Study\Models\StudySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyPileLessonSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function book(int $userId, string $name, int $position = 0): string
    {
        return app(SaveStudyPileAction::class)->handle($userId, (string) Str::ulid(), [
            'title' => $name, 'is_active' => true, 'position' => $position,
        ])->id;
    }

    private function chapter(int $userId, string $pile, int $position): string
    {
        return app(SaveStudyPileChapterAction::class)->handle($userId, $pile, (string) Str::ulid(), [
            'title' => 'Chapter '.$position, 'position' => $position, 'is_active' => true,
        ])->id;
    }

    public function test_standard_mix_orders_books_chapters_and_cards_before_uncategorized(): void
    {
        $user = $this->signIn();
        $deck = Deck::factory()->for($user)->create();
        $book = $this->book($user->id, 'Genki');
        $twenty = $this->chapter($user->id, $book, 20);
        $next = $this->chapter($user->id, $book, 21);
        $uncategorized = Card::factory()->for($deck)->create(['new_queue_position' => 1]);
        $nextCard = Card::factory()->for($deck)->create(['new_queue_position' => 2, 'book_pile_id' => $book, 'book_chapter_id' => $next]);
        $later = Card::factory()->for($deck)->create(['new_queue_position' => 3, 'book_pile_id' => $book, 'book_chapter_id' => $twenty, 'book_position' => 2]);
        $first = Card::factory()->for($deck)->create(['new_queue_position' => 4, 'book_pile_id' => $book, 'book_chapter_id' => $twenty, 'book_position' => 1]);
        $cards = $this->postJson('/api/study/lessons/start')->assertOk()->json('cards');
        $this->assertSame([$first->id, $later->id, $nextCard->id, $uncategorized->id], array_column($cards, 'syncId'));
    }

    public function test_disabled_chapters_are_skipped_but_their_reviews_remain_unified(): void
    {
        $user = $this->signIn();
        $deck = Deck::factory()->for($user)->create();
        $book = $this->book($user->id, 'Genki');
        $twenty = $this->chapter($user->id, $book, 20);
        $next = $this->chapter($user->id, $book, 21);
        Card::factory()->for($deck)->create(['new_queue_position' => 1, 'book_pile_id' => $book, 'book_chapter_id' => $twenty]);
        $ready = Card::factory()->for($deck)->create(['new_queue_position' => 2, 'book_pile_id' => $book, 'book_chapter_id' => $next]);
        Card::factory()->for($deck)->create(['new_queue_position' => 3, 'book_pile_id' => $book, 'book_chapter_id' => $next, 'variant_status' => 'locked']);
        $review = Card::factory()->for($deck)->create(['study_status' => 'review', 'due_at' => now()->subMinute(), 'book_pile_id' => $book, 'book_chapter_id' => $twenty]);
        $this->putJson("/api/study/piles/{$book}/chapters/{$twenty}", ['is_active' => false])->assertOk();
        $cards = $this->postJson('/api/study/lessons/start', ['pileId' => $book])->assertOk()->json('cards');
        $this->assertSame([$ready->id], array_column($cards, 'syncId'));
        $this->postJson('/api/study/session/start')->assertOk()->assertJsonPath('cards.0.syncId', $review->id);
        $this->getJson('/api/study/piles')->assertOk()->assertJsonPath('piles.0.new_count', 3)
            ->assertJsonPath('piles.0.ready_count', 1)->assertJsonPath('piles.0.chapters.0.ready_count', 0);
        $reserve = app(BuildStudyOfflineReserveAction::class)->handle($user->id);
        $this->assertSame([$review->id, $ready->id], $reserve['cards']->pluck('id')->all());
        $this->putJson('/api/study/piles/'.$book, ['is_active' => false])->assertOk();
        $this->postJson('/api/study/lessons/start', ['pileId' => $book])->assertOk()->assertJsonCount(0, 'cards');
        $this->postJson('/api/study/session/start')->assertOk()->assertJsonPath('cards.0.syncId', $review->id);
    }

    public function test_learn_soon_is_persistent_and_explicit_choice_can_override_a_zero_lane_weight(): void
    {
        $user = $this->signIn();
        $deck = Deck::factory()->for($user)->create();
        StudySettings::factory()->for($user)->create(['lesson_followup_lane_weight' => 0]);
        $soon = Card::factory()->for($deck)->create([
            'new_queue_position' => 99, 'learn_soon' => true,
            'priority_until' => now()->subYear(),
        ]);
        $this->postJson('/api/study/lessons/start')->assertOk()->assertJsonCount(0, 'cards');
        $this->postJson('/api/study/lessons/start', ['pileId' => 'learn-soon'])->assertOk()->assertJsonPath('cards.0.syncId', $soon->id);
        $this->postJson('/api/study/lessons/start', ['pileId' => 'uncategorized'])->assertOk()->assertJsonPath('cards.0.syncId', $soon->id);
        $this->getJson('/api/study/piles')->assertOk()->assertJsonPath('learn_soon.ready_count', 1);
        $this->postJson('/api/study/lessons/start', ['pileId' => (string) Str::ulid()])->assertNotFound();
        $this->postJson('/api/study/lessons/start', ['pileId' => 'invalid'])->assertUnprocessable();
    }
}
