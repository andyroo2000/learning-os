<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Actions\CreateStudyCardFromDraftAction;
use App\Domain\Study\Actions\SaveStudyPileAction;
use App\Domain\Study\Actions\SaveStudyPileChapterAction;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\CardBookSource;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CardBookSourceApiTest extends TestCase
{
    use RefreshDatabase;

    private function source(int $userId): array
    {
        $pile = app(SaveStudyPileAction::class)->handle($userId, (string) Str::ulid(), ['title' => 'Genki II']);
        $chapter = app(SaveStudyPileChapterAction::class)->handle($userId, $pile->id, (string) Str::ulid(), ['title' => 'Chapter 20']);

        return ['pileId' => $pile->id, 'chapterId' => $chapter->id, 'position' => 12, 'reference' => 'p. 98'];
    }

    private function cardInput(): array
    {
        return [
            'id' => strtolower((string) Str::ulid()), 'cardType' => 'recognition',
            'prompt' => ['cueText' => '会社'], 'answer' => ['expression' => '会社', 'meaning' => 'company'],
        ];
    }

    public function test_source_is_saved_synced_and_checked_on_creation_retries(): void
    {
        $user = $this->signIn();
        $source = $this->source($user->id);
        $input = [...$this->cardInput(), 'bookSource' => $source];
        $this->postJson('/api/study/cards', $input)->assertCreated()->assertJsonPath('bookSource', $source);
        $this->postJson('/api/study/cards', $input)->assertOk();
        $entry = SyncFeedEntry::where('resource_type', 'card')->sole();
        $this->assertSame($source, $entry->payload['book_source']);
        $input['bookSource']['position']++;
        $this->postJson('/api/study/cards', $input)->assertConflict();
        $this->assertSame($source, CardBookSource::payload(Card::sole()));
    }

    public function test_legacy_creation_retry_does_not_remove_a_later_source_assignment(): void
    {
        $user = $this->signIn();
        $input = $this->cardInput();
        $this->postJson('/api/study/cards', $input)->assertCreated();
        $source = $this->source($user->id);
        $this->putJson('/api/study/card-sources', ['cardIds' => [$input['id']], 'bookSource' => $source])->assertOk();
        $this->postJson('/api/study/cards', $input)->assertOk()->assertJsonPath('bookSource', $source);
        $different = [...$source, 'position' => 99];
        $this->postJson('/api/study/cards', [...$input, 'bookSource' => $different])->assertConflict();
    }

    public function test_source_is_optional_and_cannot_reference_someone_elses_book_or_wrong_chapter(): void
    {
        $user = $this->signIn();
        $source = $this->source($user->id);
        $other = $this->source($user->id);
        $mismatch = [...$source, 'chapterId' => $other['chapterId']];
        $this->postJson('/api/study/cards', [...$this->cardInput(), 'bookSource' => $mismatch])->assertNotFound();
        $this->signIn();
        $this->postJson('/api/study/cards', [...$this->cardInput(), 'bookSource' => $source])->assertNotFound();
        $this->postJson('/api/study/cards', $this->cardInput())->assertCreated()->assertJsonPath('bookSource', null);
        $this->postJson('/api/study/cards', [...$this->cardInput(), 'bookSource' => ['position' => -1]])
            ->assertUnprocessable();
    }

    public function test_drafts_and_generated_family_cards_retain_the_source(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $source = $this->source($user->id);
        $input = [...$this->cardInput(), 'creationKind' => 'text-recognition', 'bookSource' => $source];
        $response = $this->postJson('/api/study/card-drafts', $input)->assertCreated()->assertJsonPath('bookSource', $source);
        $this->postJson('/api/study/card-drafts', $input)->assertOk();
        $draft = StudyCardDraft::findOrFail($response->json('id'));
        $draft->status = 'ready';
        $draft->save();
        $result = app(CreateStudyCardFromDraftAction::class)->handle($user->id, $draft->id, (string) Str::ulid());
        $this->assertSame($source, CardBookSource::payload($result->card));
        $family = $this->postJson('/api/study/card-candidates/vocab-bundle/drafts', [
            'targetWord' => '猫', 'bookSource' => $source,
        ])->assertCreated()->json('drafts');
        $this->assertNotEmpty($family);
        foreach ($family as $member) {
            $this->assertSame($source, $member['bookSource']);
        }
    }

    public function test_batch_assignment_is_atomic_retry_safe_and_preserves_review_scheduling(): void
    {
        $user = $this->signIn();
        $source = $this->source($user->id);
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create(['study_status' => 'review', 'due_at' => now()->addDay()]);
        $due = $card->due_at->toJSON();
        $input = ['cardIds' => [$card->id, strtoupper($card->id)], 'bookSource' => $source];
        $this->putJson('/api/study/card-sources', $input)->assertOk()->assertJsonPath('changed', 1);
        $this->putJson('/api/study/card-sources', $input)->assertOk()->assertJsonPath('changed', 0);
        $this->assertSame(1, SyncFeedEntry::where('resource_type', 'card')->count());
        $this->assertSame($due, $card->fresh()->due_at->toJSON());
        $this->assertSame(1, $card->fresh()->content_revision);
        $this->assertSame($card->study_status, $card->fresh()->study_status);
        $this->assertSame($card->scheduler_state, $card->fresh()->scheduler_state);
        $foreign = Card::factory()->create();
        $this->putJson('/api/study/card-sources', ['cardIds' => [$card->id, $foreign->id], 'bookSource' => null])->assertNotFound();
        $this->assertSame($source, CardBookSource::payload($card->fresh()));
        $this->putJson('/api/study/card-sources', ['cardIds' => [$card->id], 'bookSource' => null])
            ->assertOk()->assertJsonPath('changed', 1);
        $this->assertNull(CardBookSource::payload($card->fresh()));
    }
}
