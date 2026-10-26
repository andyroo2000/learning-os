<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Actions\CreateStudyCardFromDraftAction;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearnSoonPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_is_retry_safe_and_preserves_the_original_lane_when_cleared(): void
    {
        $user = $this->signIn();
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create([
            'new_queue_position' => 1, 'selection_policy' => CardSelectionPolicy::Sprinkled,
            'priority_until' => now()->addWeek(),
        ]);
        $input = ['cardIds' => [$card->id], 'learnSoon' => true];
        $this->putJson('/api/study/learn-soon', $input)->assertOk()->assertJsonPath('changed', 1);
        $this->putJson('/api/study/learn-soon', $input)->assertOk()->assertJsonPath('changed', 0);
        $this->assertTrue(SyncFeedEntry::where('resource_type', 'card')->sole()->payload['learn_soon']);
        $this->assertSame(CardSelectionPolicy::Sprinkled, $card->fresh()->selection_policy);
        $this->putJson('/api/study/learn-soon', [...$input, 'learnSoon' => false])->assertOk();
        $this->assertFalse($card->fresh()->learn_soon);
        $this->assertFalse(SyncFeedEntry::where('resource_type', 'card')->orderByDesc('checkpoint')->first()->payload['learn_soon']);
        $this->assertSame(CardSelectionPolicy::Sprinkled, $card->fresh()->selection_policy);
        $foreign = Card::factory()->create();
        $this->putJson('/api/study/learn-soon', ['cardIds' => [$card->id, $foreign->id], 'learnSoon' => true])->assertNotFound();
        $this->assertFalse($card->fresh()->learn_soon);
    }

    public function test_clear_after_introduction_and_deleted_card_retries_are_no_ops(): void
    {
        $user = $this->signIn();
        $deck = Deck::factory()->for($user)->create();
        $introduced = Card::factory()->for($deck)->create(['study_status' => 'review', 'learn_soon' => true]);
        $deleted = Card::factory()->for($deck)->create();
        $deleted->delete();
        $this->putJson('/api/study/learn-soon', [
            'cardIds' => [$introduced->id, $deleted->id], 'learnSoon' => false,
        ])->assertOk()->assertJsonPath('changed', 0);
        $this->putJson('/api/study/learn-soon', [
            'cardIds' => [$introduced->id, $deleted->id], 'learnSoon' => true,
        ])->assertOk()->assertJsonPath('changed', 0);
        $this->assertTrue($introduced->fresh()->learn_soon);
        $this->assertSame(0, SyncFeedEntry::where('resource_type', 'card')->count());
    }

    public function test_flagged_cards_keep_a_share_of_the_usual_mix_after_the_priority_deadline(): void
    {
        $user = $this->signIn();
        $deck = Deck::factory()->for($user)->create();
        StudySettings::factory()->for($user)->create(['lesson_batch_size' => 5]);
        $standard = collect(range(1, 4))->map(fn ($position) => Card::factory()->for($deck)->create(['new_queue_position' => $position]));
        $soon = Card::factory()->for($deck)->create([
            'new_queue_position' => 100, 'learn_soon' => true, 'priority_until' => now()->subYear(),
        ]);
        $cards = $this->postJson('/api/study/lessons/start')->assertOk()->json('cards');
        $this->assertSame($standard[0]->id, $cards[0]['syncId']);
        $this->assertSame($soon->id, $cards[1]['syncId']);
        $this->assertTrue($cards[1]['learnSoon']);
        $this->assertCount(5, $cards);
    }

    public function test_creation_and_draft_commit_accept_learn_soon_without_changing_source_policy(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $input = [
            'id' => strtolower((string) Str::ulid()), 'creationKind' => 'text-recognition',
            'cardType' => 'recognition', 'prompt' => ['cueText' => '猫'],
            'answer' => ['expression' => '猫', 'meaning' => 'cat'], 'learnSoon' => true,
        ];
        $card = $this->postJson('/api/study/cards', $input)->assertCreated()->assertJsonPath('learnSoon', true)->json();
        $this->postJson('/api/study/cards', $input)->assertOk();
        $this->postJson('/api/study/cards', [...$input, 'learnSoon' => false])->assertOk()->assertJsonPath('learnSoon', true);
        $this->assertSame('standard', $card['selectionPolicy']);
        $draftResponse = $this->postJson('/api/study/card-drafts', $input)->assertCreated()->assertJsonPath('learnSoon', true);
        $draft = StudyCardDraft::findOrFail($draftResponse->json('id'));
        $draft->status = 'ready';
        $draft->save();
        $result = app(CreateStudyCardFromDraftAction::class)->handle($user->id, $draft->id, (string) Str::ulid());
        $this->assertTrue($result->card->learn_soon);
        $family = $this->postJson('/api/study/card-candidates/vocab-bundle/drafts', ['targetWord' => '猫', 'learnSoon' => true])
            ->assertCreated()->json('drafts');
        foreach ($family as $member) {
            $this->assertTrue($member['learnSoon']);
        }
    }
}
