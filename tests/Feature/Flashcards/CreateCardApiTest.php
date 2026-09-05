<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
