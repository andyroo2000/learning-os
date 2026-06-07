<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Exceptions\CardConflictException;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Enums\StudyVocabVariantKind;
use App\Domain\Study\Enums\StudyVocabVariantStatus;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class CreateCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_card_for_a_deck(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $course = Course::factory()->create();
            $deck = Deck::factory()->create([
                'user_id' => $course->user_id,
                'course_id' => $course->id,
            ]);

            $result = app(CreateCardAction::class)->handle(
                CreateCardData::fromInput(
                    userId: $deck->user_id,
                    deckId: $deck->id,
                    frontText: 'ciao',
                    backText: 'hello',
                ),
            );

            $card = $result->card;

            $this->assertTrue($result->wasCreated);
            $this->assertTrue(Str::isUlid($card->id));

            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'deck_id' => $deck->id,
                'front_text' => 'ciao',
                'back_text' => 'hello',
                'new_queue_position' => 1,
            ]);

            $entry = SyncFeedEntry::query()->sole();

            $this->assertSame($deck->user_id, $entry->user_id);
            $this->assertSame('flashcards', $entry->domain);
            $this->assertSame('card', $entry->resource_type);
            $this->assertSame($card->id, $entry->resource_id);
            $this->assertSame(SyncFeedOperation::Create, $entry->operation);
            $this->assertSame([
                'id' => $card->id,
                'deck_id' => $deck->id,
                'course_id' => $course->id,
                'import_job_id' => null,
                'source_kind' => null,
                'source_card_id' => null,
                'source_note_id' => null,
                'source_deck_id' => null,
                'source_notetype_name' => null,
                'source_template_ord' => null,
                'front_text' => 'ciao',
                'back_text' => 'hello',
                'card_type' => 'recognition',
                'prompt_json' => null,
                'answer_json' => null,
                'search_text' => 'ciao hello',
                'study_status' => 'new',
                'new_queue_position' => 1,
                'scheduler_state' => [
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
                ],
                'variant_group_id' => null,
                'variant_sentence_id' => null,
                'variant_kind' => null,
                'variant_stage' => null,
                'variant_status' => null,
                'variant_unlocked_at' => null,
                'due_at' => null,
                'introduced_at' => null,
                'failed_at' => null,
                'last_reviewed_at' => null,
                'created_at' => $card->created_at?->toJSON(),
                'updated_at' => $card->updated_at?->toJSON(),
                'deleted_at' => null,
            ], $entry->payload);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_client_id_retries_compare_variant_timestamps_at_persisted_precision(): void
    {
        $deck = Deck::factory()->create();
        $cardId = strtolower((string) Str::ulid());
        $variantUnlockedAt = Carbon::parse('2026-06-04T14:15:30.987654Z');

        $data = CreateCardData::fromInput(
            userId: $deck->user_id,
            deckId: $deck->id,
            frontText: 'front',
            backText: 'back',
            variantGroupId: 'group-1',
            variantSentenceId: 'sentence-1',
            variantKind: StudyVocabVariantKind::SentenceAudioRecognition,
            variantStage: 1,
            variantStatus: StudyVocabVariantStatus::Available,
            variantUnlockedAt: $variantUnlockedAt,
            id: $cardId,
        );

        $firstResult = app(CreateCardAction::class)->handle($data);
        $secondResult = app(CreateCardAction::class)->handle($data);

        $this->assertTrue($firstResult->wasCreated);
        $this->assertFalse($secondResult->wasCreated);
        $this->assertSame($cardId, $secondResult->card->id);
        $this->assertSame(1, Card::query()->count());

        $entry = SyncFeedEntry::query()->sole();
        $this->assertSame('group-1', $entry->payload['variant_group_id']);
        $this->assertSame('sentence-1', $entry->payload['variant_sentence_id']);
        $this->assertSame(StudyVocabVariantKind::SentenceAudioRecognition->value, $entry->payload['variant_kind']);
        $this->assertSame(1, $entry->payload['variant_stage']);
        $this->assertSame(StudyVocabVariantStatus::Available->value, $entry->payload['variant_status']);
        $this->assertSame('2026-06-04T14:15:30.000000Z', $entry->payload['variant_unlocked_at']);
    }

    public function test_it_creates_a_card_with_a_client_card_type(): void
    {
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                cardType: ' PRODUCTION ',
            ),
        );

        $this->assertSame(CardType::Production, $result->card->card_type);
        $this->assertDatabaseHas('cards', [
            'id' => $result->card->id,
            'card_type' => 'production',
        ]);
        $this->assertSame('production', SyncFeedEntry::query()->sole()->payload['card_type']);
    }

    public function test_it_creates_a_card_with_structured_content(): void
    {
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'What is ATP?',
                backText: 'Cellular energy currency.',
                promptJson: ['type' => 'text', 'text' => 'What is ATP?'],
                answerJson: ['type' => 'text', 'text' => 'Cellular energy currency.'],
            ),
        );

        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $result->card->prompt_json);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $result->card->answer_json);
        $this->assertSame(
            'What is ATP? Cellular energy currency. text What is ATP? text Cellular energy currency.',
            $result->card->search_text,
        );
        $this->assertDatabaseHas('cards', [
            'id' => $result->card->id,
            'prompt_json' => json_encode(['type' => 'text', 'text' => 'What is ATP?']),
            'answer_json' => json_encode(['type' => 'text', 'text' => 'Cellular energy currency.']),
            'search_text' => 'What is ATP? Cellular energy currency. text What is ATP? text Cellular energy currency.',
        ]);

        $payload = SyncFeedEntry::query()->sole()->payload;

        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $payload['prompt_json']);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $payload['answer_json']);
        $this->assertSame(
            'What is ATP? Cellular energy currency. text What is ATP? text Cellular energy currency.',
            $payload['search_text'],
        );
    }

    public function test_it_appends_new_cards_to_the_users_new_card_queue(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $otherDeck = Deck::factory()->for($user)->create();
        $otherUserDeck = Deck::factory()->create();

        $first = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $user->id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
            ),
        )->card;
        $second = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $user->id,
                deckId: $otherDeck->id,
                frontText: 'arrivederci',
                backText: 'goodbye',
            ),
        )->card;
        $otherUserCard = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $otherUserDeck->user_id,
                deckId: $otherUserDeck->id,
                frontText: 'hola',
                backText: 'hello',
            ),
        )->card;

        $this->assertSame(1, $first->new_queue_position);
        $this->assertSame(2, $second->new_queue_position);
        $this->assertSame(1, $otherUserCard->new_queue_position);

        $this->assertDatabaseHas('cards', [
            'id' => $first->id,
            'new_queue_position' => 1,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $second->id,
            'new_queue_position' => 2,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $otherUserCard->id,
            'new_queue_position' => 1,
        ]);
    }

    public function test_it_uses_a_provided_ulid(): void
    {
        $deck = Deck::factory()->create();
        $id = (string) Str::ulid();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: strtoupper($id),
            ),
        );

        $card = $result->card;

        $this->assertTrue($result->wasCreated);
        $this->assertSame(strtolower($id), $card->id);

        $this->assertDatabaseHas('cards', [
            'id' => strtolower($id),
            'deck_id' => $deck->id,
        ]);
        $this->assertDatabaseHas('sync_feed_entries', [
            'user_id' => $deck->user_id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => strtolower($id),
            'operation' => 'create',
        ]);
    }

    public function test_it_rolls_back_client_provided_id_creates_when_feed_recording_fails(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());
        $createCard = new CreateCardAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        try {
            $createCard->handle(
                CreateCardData::fromInput(
                    userId: $deck->user_id,
                    deckId: $deck->id,
                    frontText: 'ciao',
                    backText: 'hello',
                    id: $id,
                ),
            );

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseMissing('cards', ['id' => $id]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_rolls_back_server_generated_id_creates_when_feed_recording_fails(): void
    {
        $deck = Deck::factory()->create();
        $createCard = new CreateCardAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        try {
            $createCard->handle(
                CreateCardData::fromInput(
                    userId: $deck->user_id,
                    deckId: $deck->id,
                    frontText: 'ciao',
                    backText: 'hello',
                ),
            );

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseCount('cards', 0);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_trims_text_inputs(): void
    {
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: "  {$deck->id}  ",
                frontText: '  ciao  ',
                backText: '  hello  ',
            ),
        );

        $card = $result->card;

        $this->assertTrue($result->wasCreated);
        $this->assertSame($deck->id, $card->deck_id);
        $this->assertSame('ciao', $card->front_text);
        $this->assertSame('hello', $card->back_text);
    }

    public function test_it_normalizes_uppercase_deck_ids(): void
    {
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: strtoupper($deck->id),
                frontText: 'ciao',
                backText: 'hello',
            ),
        );

        $card = $result->card;

        $this->assertTrue($result->wasCreated);
        $this->assertSame($deck->id, $card->deck_id);
    }

    public function test_it_returns_existing_card_for_idempotent_retries(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        $existingCard = Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: '  ciao  ',
                backText: '  hello  ',
                id: strtoupper($id),
            ),
        );

        $card = $result->card;

        $this->assertTrue($existingCard->is($card));
        $this->assertFalse($result->wasCreated);
        $this->assertDatabaseCount('cards', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_matches_legacy_untrimmed_text_for_idempotent_retries(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        $existingCard = Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => '  ciao  ',
            'back_text' => '  hello  ',
        ]);

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: $id,
            ),
        );

        $card = $result->card;

        $this->assertTrue($existingCard->is($card));
        $this->assertFalse($result->wasCreated);
        $this->assertDatabaseCount('cards', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_matches_legacy_null_card_type_for_idempotent_retries(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        $existingCard = new Card;
        $existingCard->setRawAttributes([
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => null,
            'deleted_at' => null,
        ], sync: true);
        $existingCard->setRelation('deck', $deck);

        $resolveExistingCard = new ReflectionMethod(CreateCardAction::class, 'resolveExistingCard');
        $resolveExistingCard->setAccessible(true);

        $result = $resolveExistingCard->invoke(
            app(CreateCardAction::class),
            $existingCard,
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                cardType: CardType::Recognition,
                id: $id,
            ),
        );

        $this->assertSame($existingCard, $result);
        $this->assertDatabaseCount('cards', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_matches_reordered_structured_content_for_idempotent_retries(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        $existingCard = Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'prompt_json' => [
                'text' => 'What is ATP?',
                'type' => 'text',
            ],
            'answer_json' => [
                'text' => 'Cellular energy currency.',
                'type' => 'text',
            ],
        ]);

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'What is ATP?',
                backText: 'Cellular energy currency.',
                promptJson: [
                    'type' => 'text',
                    'text' => 'What is ATP?',
                ],
                answerJson: [
                    'type' => 'text',
                    'text' => 'Cellular energy currency.',
                ],
                id: $id,
            ),
        );

        $this->assertTrue($existingCard->is($result->card));
        $this->assertFalse($result->wasCreated);
        $this->assertDatabaseCount('cards', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_returns_existing_card_when_concurrent_create_wins_the_race(): void
    {
        $deck = Deck::factory()->create();
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
                    'front_text' => 'ciao',
                    'back_text' => 'hello',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            },
        );

        $result = $createCard->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: $id,
            ),
        );

        $card = $result->card;

        $this->assertTrue($inserted);
        $this->assertSame($id, $card->id);
        $this->assertFalse($result->wasCreated);
        $this->assertDatabaseCount('cards', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rethrows_the_unique_exception_when_the_race_winner_disappears_before_refetch(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());
        $inserted = false;
        $deleted = false;

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
                    'front_text' => 'ciao',
                    'back_text' => 'hello',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            },
            afterClientIdUniqueConflict: function (CreateCardData $data) use (&$deleted): void {
                $deleted = DB::table('cards')->where('id', $data->id)->delete() === 1;
            },
        );

        try {
            $createCard->handle(
                CreateCardData::fromInput(
                    userId: $deck->user_id,
                    deckId: $deck->id,
                    frontText: 'ciao',
                    backText: 'hello',
                    id: $id,
                ),
            );

            $this->fail('The original unique constraint exception was not rethrown.');
        } catch (QueryException) {
            $this->assertTrue($inserted);
            $this->assertTrue($deleted);
            $this->assertDatabaseCount('cards', 0);
        }
    }

    public function test_it_rejects_client_provided_ulid_conflicts(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $this->expectException(CardConflictException::class);
        $this->expectExceptionMessage('Card ID already exists with different metadata.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'salve',
                backText: 'hello',
                id: $id,
            ),
        );
    }

    public function test_it_rejects_same_user_cross_deck_ulid_conflicts(): void
    {
        $sourceDeck = Deck::factory()->create();
        $targetDeck = Deck::factory()->for($sourceDeck->user)->create();
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($sourceDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $this->expectException(CardConflictException::class);
        $this->expectExceptionMessage('Card ID already exists with different metadata.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $targetDeck->user_id,
                deckId: $targetDeck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: $id,
            ),
        );
    }

    public function test_it_throws_for_cross_user_ulid_conflicts_before_http_hides_them(): void
    {
        $targetDeck = Deck::factory()->create();
        $otherDeck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($otherDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $this->expectException(CardConflictException::class);
        $this->expectExceptionMessage('Card ID already exists with different metadata.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $targetDeck->user_id,
                deckId: $targetDeck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: $id,
            ),
        );
    }

    public function test_it_fails_when_existing_card_owner_cannot_be_resolved(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->id = strtolower((string) Str::ulid());
        $card->setRelation('deck', null);

        Log::spy();

        $ownerIdFor = new ReflectionMethod(CreateCardAction::class, 'ownerIdFor');
        $ownerIdFor->setAccessible(true);

        try {
            $ownerIdFor->invoke(app(CreateCardAction::class), $card);

            $this->fail('Owner resolution did not fail for an orphaned card.');
        } catch (LogicException $exception) {
            $this->assertSame('Card deck owner could not be resolved.', $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Card conflict owner could not be resolved.', [
                'card_id' => $card->id,
                'deck_id' => $card->deck_id,
            ]);
    }

    public function test_it_requires_the_deck_relation_for_conflict_owner_resolution(): void
    {
        $card = Card::factory()->create();
        $card->unsetRelation('deck');

        $ownerIdFor = new ReflectionMethod(CreateCardAction::class, 'ownerIdFor');
        $ownerIdFor->setAccessible(true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck relation must be eager-loaded for conflict resolution.');

        $ownerIdFor->invoke(app(CreateCardAction::class), $card);
    }

    public function test_it_rejects_non_positive_user_ids(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card user ID must be a positive integer.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: 0,
                deckId: strtolower((string) Str::ulid()),
                frontText: 'ciao',
                backText: 'hello',
            ),
        );
    }

    public function test_it_rejects_invalid_deck_ulid(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck ID must be a valid ULID.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $user->id,
                deckId: 'not-a-ulid',
                frontText: 'ciao',
                backText: 'hello',
            ),
        );
    }

    public function test_it_rejects_missing_deck(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck does not exist.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $user->id,
                deckId: strtolower((string) Str::ulid()),
                frontText: 'ciao',
                backText: 'hello',
            ),
        );
    }

    public function test_it_rejects_another_users_deck(): void
    {
        $deck = Deck::factory()->create();
        $otherUser = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck does not exist.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $otherUser->id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
            ),
        );
    }

    public function test_it_rejects_blank_front_text(): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card front text is required.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: '   ',
                backText: 'hello',
            ),
        );
    }

    public function test_it_rejects_blank_back_text(): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card back text is required.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: '   ',
            ),
        );
    }

    public function test_it_rejects_invalid_provided_ulid(): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card ID must be a valid ULID.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: 'not-a-ulid',
            ),
        );
    }

    public function test_it_rejects_blank_card_type_for_direct_callers(): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card type must not be blank when provided.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                cardType: '   ',
            ),
        );
    }

    public function test_it_rejects_malformed_card_type_for_direct_callers(): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card type must be one of: recognition, production, cloze.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                cardType: 'reverse',
            ),
        );
    }
}
