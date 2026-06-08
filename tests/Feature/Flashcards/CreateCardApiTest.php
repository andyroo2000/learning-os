<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Flashcards\Concerns\UsesStudyCardRateLimitOverrides;
use Tests\TestCase;

class CreateCardApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesStudyCardRateLimitOverrides;

    public function test_it_creates_a_card(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);

            $response = $this->postJson('/api/cards', [
                'deck_id' => $deck->id,
                'front_text' => 'ciao',
                'back_text' => 'hello',
            ]);

            $response
                ->assertCreated()
                ->assertJsonPath('data.deck_id', $deck->id)
                ->assertJsonPath('data.front_text', 'ciao')
                ->assertJsonPath('data.back_text', 'hello')
                ->assertJsonPath('data.card_type', 'recognition')
                ->assertJsonPath('data.prompt_json', null)
                ->assertJsonPath('data.answer_json', null)
                ->assertJsonPath('data.search_text', 'ciao hello')
                ->assertJsonPath('data.scheduler_state.due', '2026-06-04T12:00:00.000000Z')
                ->assertJsonPath('data.scheduler_state.state', 0)
                ->assertJsonPath('data.scheduler_state.reps', 0)
                ->assertJsonMissingPath('data.media_assets')
                ->assertJsonStructure([
                    'data' => [
                        'id',
                        'deck_id',
                        'front_text',
                        'back_text',
                        'card_type',
                        'prompt_json',
                        'answer_json',
                        'search_text',
                        'study_status',
                        'new_queue_position',
                        'scheduler_state',
                        'due_at',
                        'introduced_at',
                        'failed_at',
                        'last_reviewed_at',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ],
                ]);

            $this->assertTrue(Str::isUlid($response->json('data.id')));

            $this->assertDatabaseHas('cards', [
                'id' => $response->json('data.id'),
                'deck_id' => $deck->id,
                'front_text' => 'ciao',
                'back_text' => 'hello',
                'card_type' => 'recognition',
                'prompt_json' => null,
                'answer_json' => null,
                'search_text' => 'ciao hello',
                'study_status' => 'new',
                'new_queue_position' => 1,
                'due_at' => null,
                'introduced_at' => null,
                'failed_at' => null,
                'last_reviewed_at' => null,
            ]);

            $this->assertSame([
                'due' => '2026-06-04T12:00:00.000000Z',
                'stability' => 0.1,
                'difficulty' => 5,
                'elapsed_days' => 0,
                'scheduled_days' => 0,
                'learning_steps' => 0,
                'reps' => 0,
                'lapses' => 0,
                'state' => 0,
                'last_review' => null,
            ], Card::query()->findOrFail($response->json('data.id'))->scheduler_state);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_accepts_a_client_provided_ulid(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = (string) Str::ulid();

        $response = $this->postJson('/api/cards', [
            'id' => strtoupper($id),
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', strtolower($id));

        $this->assertDatabaseHas('cards', [
            'id' => strtolower($id),
            'deck_id' => $deck->id,
        ]);
    }

    public function test_create_is_rate_limited_by_user(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherUser = User::factory()->create();
        $otherDeck = $this->deckFor($otherUser);

        $this->withStudyCardRateLimitOverride(
            StudyCardCreateRateLimiter::NAME,
            [$user->id, $otherUser->id],
            function () use ($deck, $otherDeck, $otherUser, $user): void {
                foreach ([1, 2] as $attempt) {
                    $this
                        ->postJson('/api/cards', $this->cardCreatePayload($deck->id, "front {$attempt}"))
                        ->assertCreated();
                }

                $this->signIn($otherUser);

                $this
                    ->postJson('/api/cards', $this->cardCreatePayload($otherDeck->id, 'other front'))
                    ->assertCreated();

                $this->signIn($user);

                $this
                    ->postJson('/api/cards', $this->cardCreatePayload($deck->id, 'blocked front'))
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->getJson('/api/cards')
                    ->assertOk()
                    ->assertJsonCount(2, 'data');

                $this->assertSame(2, Card::query()->whereBelongsTo($deck)->count());
                $this->assertSame(1, Card::query()->whereBelongsTo($otherDeck)->count());
                $this->assertDatabaseMissing('cards', [
                    'deck_id' => $deck->id,
                    'front_text' => 'blocked front',
                ]);
            },
        );
    }

    public function test_it_ignores_client_provided_study_state(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);

            $response = $this->postJson('/api/cards', [
                'deck_id' => $deck->id,
                'front_text' => 'ciao',
                'back_text' => 'hello',
                'study_status' => 'review',
                'new_queue_position' => 99,
                'scheduler_state' => ['state' => 2],
                'due_at' => '2026-06-05T14:15:00Z',
                'introduced_at' => '2026-06-01T14:15:00Z',
                'failed_at' => '2026-06-02T14:15:00Z',
                'last_reviewed_at' => '2026-06-03T14:15:00Z',
            ]);

            $response
                ->assertCreated()
                ->assertJsonPath('data.study_status', 'new')
                ->assertJsonPath('data.new_queue_position', 1)
                ->assertJsonPath('data.scheduler_state.due', '2026-06-04T12:00:00.000000Z')
                ->assertJsonPath('data.scheduler_state.state', 0)
                ->assertJsonPath('data.due_at', null)
                ->assertJsonPath('data.introduced_at', null)
                ->assertJsonPath('data.failed_at', null)
                ->assertJsonPath('data.last_reviewed_at', null);

            $this->assertDatabaseHas('cards', [
                'id' => $response->json('data.id'),
                'study_status' => 'new',
                'new_queue_position' => 1,
                'due_at' => null,
                'introduced_at' => null,
                'failed_at' => null,
                'last_reviewed_at' => null,
            ]);

            $this->assertSame(0, Card::query()->findOrFail($response->json('data.id'))->scheduler_state['state']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_accepts_card_type(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', [
                'deck_id' => $deck->id,
                'front_text' => 'ciao',
                'back_text' => 'hello',
                'card_type' => ' PRODUCTION ',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.card_type', 'production');

        $this->assertDatabaseHas('cards', [
            'id' => $response->json('data.id'),
            'card_type' => 'production',
        ]);
    }

    public function test_it_accepts_structured_content(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'prompt_json' => ['type' => 'text', 'text' => 'What is ATP?'],
            'answer_json' => ['type' => 'text', 'text' => 'Cellular energy currency.'],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.prompt_json.type', 'text')
            ->assertJsonPath('data.prompt_json.text', 'What is ATP?')
            ->assertJsonPath('data.answer_json.type', 'text')
            ->assertJsonPath('data.answer_json.text', 'Cellular energy currency.');

        $card = Card::query()->findOrFail($response->json('data.id'));

        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $card->prompt_json);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $card->answer_json);
    }

    public function test_it_accepts_null_structured_content(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'prompt_json' => null,
            'answer_json' => null,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.prompt_json', null)
            ->assertJsonPath('data.answer_json', null);

        $card = Card::query()->findOrFail($response->json('data.id'));

        $this->assertNull($card->prompt_json);
        $this->assertNull($card->answer_json);
    }

    public function test_it_creates_a_card_with_variant_metadata(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        $payload = [
            'id' => strtoupper($id),
            'deck_id' => $deck->id,
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => ' vocab-group-1 ',
            'variant_sentence_id' => ' sentence-1 ',
            'variant_kind' => ' SENTENCE_CLOZE ',
            'variant_stage' => ' +3 ',
            'variant_status' => ' AVAILABLE ',
            'variant_unlocked_at' => '2026-06-04T14:15:30.123456+05:30',
        ];

        $firstResponse = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', $payload);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.variant_group_id', 'vocab-group-1')
            ->assertJsonPath('data.variant_sentence_id', 'sentence-1')
            ->assertJsonPath('data.variant_kind', VocabVariantKind::SentenceCloze->value)
            ->assertJsonPath('data.variant_stage', 3)
            ->assertJsonPath('data.variant_status', VocabVariantStatus::Available->value)
            ->assertJsonPath('data.variant_unlocked_at', '2026-06-04T08:45:30.000000Z');

        $secondResponse = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', $payload);

        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.variant_group_id', 'vocab-group-1')
            ->assertJsonPath('data.variant_unlocked_at', '2026-06-04T08:45:30.000000Z');

        $card = Card::query()->findOrFail($id);
        $this->assertSame('vocab-group-1', $card->variant_group_id);
        $this->assertSame('sentence-1', $card->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceCloze->value, $card->variant_kind);
        $this->assertSame(3, $card->variant_stage);
        $this->assertSame(VocabVariantStatus::Available->value, $card->variant_status);
        $this->assertSame('2026-06-04T08:45:30.000000Z', $card->variant_unlocked_at?->toJSON());

        $entry = SyncFeedEntry::query()->sole();
        $this->assertEquals(CardSyncPayload::fromCard($card), $entry->payload);
        $this->assertDatabaseCount('cards', 1);
    }

    public function test_it_normalizes_utc_offset_variant_unlocked_at(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => '時間',
            'back_text' => 'time',
            'variant_unlocked_at' => '2026-06-04T08:45:30+00:00',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.variant_unlocked_at', '2026-06-04T08:45:30.000000Z');
    }

    public function test_it_normalizes_padded_uppercase_client_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        // TrimStrings processes JSON bodies too; disable it to prove StoreCardRequest normalizes independently.
        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', [
                'id' => '  '.strtoupper($id).'  ',
                'deck_id' => '  '.strtoupper($deck->id).'  ',
                'front_text' => 'ciao',
                'back_text' => 'hello',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.deck_id', $deck->id);

        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_trims_client_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $lowercaseId = strtolower((string) Str::ulid());

        // TrimStrings processes JSON bodies too; disable it to prove StoreCardRequest normalizes independently.
        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', [
                'id' => "  {$lowercaseId}  ",
                'deck_id' => "  {$deck->id}  ",
                'front_text' => 'ciao',
                'back_text' => 'hello',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $lowercaseId)
            ->assertJsonPath('data.deck_id', $deck->id);

        $this->assertDatabaseHas('cards', [
            'id' => $lowercaseId,
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_lowercases_client_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        // TrimStrings processes JSON bodies too; disable it to prove StoreCardRequest normalizes independently.
        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', [
                'id' => strtoupper($id),
                'deck_id' => strtoupper($deck->id),
                'front_text' => 'ciao',
                'back_text' => 'hello',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.deck_id', $deck->id);

        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_returns_existing_card_for_idempotent_retries(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        $payload = [
            'id' => strtoupper($id),
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ];

        $firstResponse = $this->postJson('/api/cards', $payload);
        $secondResponse = $this->postJson('/api/cards', $payload);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.deck_id', $deck->id)
            ->assertJsonPath('data.front_text', 'ciao')
            ->assertJsonPath('data.back_text', 'hello')
            ->assertJsonPath('data.card_type', 'recognition')
            ->assertJsonPath('data.prompt_json', null)
            ->assertJsonPath('data.answer_json', null);

        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.deck_id', $deck->id)
            ->assertJsonPath('data.front_text', 'ciao')
            ->assertJsonPath('data.back_text', 'hello')
            ->assertJsonPath('data.card_type', 'recognition')
            ->assertJsonPath('data.prompt_json', null)
            ->assertJsonPath('data.answer_json', null);

        $this->assertDatabaseCount('cards', 1);
    }

    public function test_it_accepts_uppercase_deck_ids(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => strtoupper($deck->id),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.deck_id', $deck->id);

        $this->assertDatabaseHas('cards', [
            'id' => $response->json('data.id'),
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_rejects_client_provided_ulid_conflicts(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'salve',
            'back_text' => 'hello',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_id_conflict');

        $this->assertDatabaseCount('cards', 1);
    }

    public function test_it_rejects_client_provided_ulid_variant_metadata_conflicts(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => 'vocab-group-1',
            'variant_sentence_id' => 'sentence-1',
            'variant_kind' => VocabVariantKind::SentenceCloze,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Available,
            'variant_unlocked_at' => '2026-06-04T08:45:30.000000Z',
        ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => 'vocab-group-2',
            'variant_sentence_id' => 'sentence-1',
            'variant_kind' => VocabVariantKind::SentenceCloze->value,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Available->value,
            'variant_unlocked_at' => '2026-06-04T08:45:30.000000Z',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_id_conflict');

        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'variant_group_id' => 'vocab-group-1',
        ]);
        $this->assertDatabaseCount('cards', 1);
    }

    public function test_it_rejects_same_user_cross_deck_ulid_conflicts(): void
    {
        $user = $this->signIn();
        $sourceDeck = $this->deckFor($user);
        $targetDeck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($sourceDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $targetDeck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_id_conflict');

        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $sourceDeck->id,
        ]);
        $this->assertDatabaseMissing('cards', [
            'id' => $id,
            'deck_id' => $targetDeck->id,
        ]);
    }

    public function test_it_returns_gone_for_owned_soft_deleted_cards_with_matching_metadata(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        $card = Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->delete();

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertGone()
            ->assertJsonPath('message', 'Card ID belongs to a deleted card.')
            ->assertJsonPath('reason', 'card_deleted');

        $this->assertSoftDeleted('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_returns_gone_for_owned_soft_deleted_cards_with_different_metadata(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        $card = Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->delete();

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'salve',
            'back_text' => 'hello',
        ]);

        $response
            ->assertGone()
            ->assertJsonPath('message', 'Card ID belongs to a deleted card.')
            ->assertJsonPath('reason', 'card_deleted');
    }

    public function test_it_returns_gone_for_same_user_cross_deck_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $sourceDeck = $this->deckFor($user);
        $targetDeck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        $card = Card::factory()->for($sourceDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->delete();

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $targetDeck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertGone()
            ->assertJsonPath('message', 'Card ID belongs to a deleted card.')
            ->assertJsonPath('reason', 'card_deleted');

        $this->assertSoftDeleted('cards', [
            'id' => $id,
            'deck_id' => $sourceDeck->id,
        ]);
    }

    public function test_it_returns_gone_for_idempotent_retries_after_the_deck_is_soft_deleted(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $deck->delete();

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertGone()
            ->assertJsonPath('message', 'Card ID belongs to a deleted card.')
            ->assertJsonPath('reason', 'card_deleted');

        $this->assertSoftDeleted('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_returns_gone_when_the_deck_is_soft_deleted_but_the_card_row_survives(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        // Bypass the model cascade so the card row stays active while the deck is tombstoned.
        DB::table('decks')
            ->where('id', $deck->id)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertGone()
            ->assertJsonPath('message', 'Card ID belongs to a deleted deck.')
            ->assertJsonPath('reason', 'deck_deleted');

        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'deleted_at' => null,
        ]);
    }

    public function test_it_hides_cross_user_deck_deleted_tombstones(): void
    {
        $this->signIn();
        $otherDeck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($otherDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        // Bypass the model cascade so the card row stays active while the deck is tombstoned.
        DB::table('decks')
            ->where('id', $otherDeck->id)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $otherDeck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');
    }

    public function test_it_hides_cross_user_card_deleted_tombstones_when_the_deck_is_also_deleted(): void
    {
        $this->signIn();
        $otherDeck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        $card = Card::factory()->for($otherDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->delete();

        DB::table('decks')
            ->where('id', $otherDeck->id)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $otherDeck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');
    }

    public function test_it_hides_idempotent_retries_for_other_users_cards(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor(User::factory()->create());
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($otherDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');

        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $otherDeck->id,
        ]);
        $this->assertDatabaseMissing('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_hides_idempotent_retries_for_other_users_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor(User::factory()->create());
        $id = strtolower((string) Str::ulid());

        $card = Card::factory()->for($otherDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->delete();

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');

        $this->assertSoftDeleted('cards', [
            'id' => $id,
            'deck_id' => $otherDeck->id,
        ]);
    }

    public function test_it_hides_cross_user_conflicts_when_concurrent_create_wins_the_race(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor(User::factory()->create());
        $id = strtolower((string) Str::ulid());
        $inserted = false;
        $caughtUniqueConflict = false;

        $createCard = new CreateCardAction(
            recordSyncFeedEntry: app(RecordSyncFeedEntryAction::class),
            afterClientIdPrecheckMiss: function (CreateCardData $data) use (&$inserted, $otherDeck): void {
                if ($inserted || $data->id === null) {
                    return;
                }

                $inserted = true;

                DB::table('cards')->insert([
                    'id' => $data->id,
                    'deck_id' => $otherDeck->id,
                    'front_text' => 'ciao',
                    'back_text' => 'hello',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            },
            afterClientIdUniqueConflict: function () use (&$caughtUniqueConflict): void {
                $caughtUniqueConflict = true;
            },
        );

        $this->app->instance(CreateCardAction::class, $createCard);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found');

        $this->assertTrue($inserted);
        $this->assertTrue($caughtUniqueConflict);
        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $otherDeck->id,
        ]);
    }

    public function test_it_rejects_same_user_conflicts_when_concurrent_create_wins_the_race(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());
        $inserted = false;

        $createCard = new CreateCardAction(
            recordSyncFeedEntry: app(RecordSyncFeedEntryAction::class),
            afterClientIdPrecheckMiss: function (CreateCardData $data) use (&$inserted, $deck): void {
                if ($inserted || $data->id === null) {
                    return;
                }

                $inserted = true;

                DB::table('cards')->insert([
                    'id' => $data->id,
                    'deck_id' => $deck->id,
                    'front_text' => 'salve',
                    'back_text' => 'hello',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            },
        );

        $this->app->instance(CreateCardAction::class, $createCard);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_id_conflict');

        $this->assertTrue($inserted);
        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'salve',
            'back_text' => 'hello',
        ]);
        $this->assertDatabaseCount('cards', 1);
    }

    public function test_it_normalizes_text_inputs(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', [
                'deck_id' => "  {$deck->id}  ",
                'front_text' => '  ciao  ',
                'back_text' => '  hello  ',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.deck_id', $deck->id)
            ->assertJsonPath('data.front_text', 'ciao')
            ->assertJsonPath('data.back_text', 'hello');

        $this->assertDatabaseHas('cards', [
            'id' => $response->json('data.id'),
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'search_text' => 'ciao hello',
        ]);
    }

    public function test_it_rejects_blank_text_inputs_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', [
                'deck_id' => $deck->id,
                'front_text' => '   ',
                'back_text' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text', 'back_text']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_invalid_input(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/cards', [
            'id' => 'not-a-ulid',
            'deck_id' => 'also-not-a-ulid',
            'front_text' => '   ',
            'back_text' => '   ',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'deck_id', 'front_text', 'back_text']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_array_ulid_inputs(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/cards', [
            'id' => [strtolower((string) Str::ulid())],
            'deck_id' => [strtolower((string) Str::ulid())],
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'deck_id']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_blank_card_type_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/cards', [
                'deck_id' => $deck->id,
                'front_text' => 'ciao',
                'back_text' => 'hello',
                'card_type' => '   ',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_malformed_card_type(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => 'reverse',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_null_card_type(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => null,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_array_card_type(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => ['production'],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_non_array_structured_content(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'prompt_json' => 'What is ATP?',
            'answer_json' => 'Cellular energy currency.',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt_json', 'answer_json']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_invalid_variant_metadata(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => '犬',
            'back_text' => 'dog',
            'variant_group_id' => str_repeat('a', 65),
            'variant_sentence_id' => ['sentence-1'],
            'variant_kind' => 'sentence-audio-recognition',
            'variant_stage' => 0,
            'variant_status' => ['available'],
            'variant_unlocked_at' => 'yesterday',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'variant_group_id',
                'variant_sentence_id',
                'variant_kind',
                'variant_stage',
                'variant_status',
                'variant_unlocked_at',
            ]);

        $this->assertDatabaseCount('cards', 0);

        $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => '犬',
            'back_text' => 'dog',
            'variant_unlocked_at' => 1234567890,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variant_unlocked_at']);

        $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => '犬',
            'back_text' => 'dog',
            'variant_unlocked_at' => '2026-06-04T14:15:30',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variant_unlocked_at']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_missing_deck(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/cards', [
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_another_users_deck(): void
    {
        $this->signIn();
        $otherDeck = Deck::factory()->create();

        $response = $this->postJson('/api/cards', [
            'deck_id' => $otherDeck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_valid_card_id_when_requested_deck_belongs_to_another_user(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $otherDeck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->assertDatabaseCount('cards', 1);
    }

    public function test_it_rejects_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $deck->delete();

        $response = $this->postJson('/api/cards', [
            'id' => strtolower((string) Str::ulid()),
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_requires_authentication(): void
    {
        $deck = Deck::factory()->create();

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount('cards', 0);
    }

    /**
     * @return array{deck_id: string, front_text: string, back_text: string}
     */
    private function cardCreatePayload(string $deckId, string $frontText): array
    {
        return [
            'deck_id' => $deckId,
            'front_text' => $frontText,
            'back_text' => 'back '.$frontText,
        ];
    }
}
