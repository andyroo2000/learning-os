<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Actions\DeleteDeckAction;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Actions\ResolveManualStudyDeckAction;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class StoreStudyCardCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_manual_study_card_in_the_default_study_deck(): void
    {
        $user = $this->signIn();

        $response = $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => [
                'cueText' => '会社',
                'cueReading' => 'かいしゃ',
            ],
            'answer' => [
                'expression' => '会社',
                'meaning' => 'company',
            ],
            'study_status' => 'review',
            'new_queue_position' => 99,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('cardType', 'recognition')
            ->assertJsonPath('noteId', null)
            ->assertJsonPath('prompt.cueText', '会社')
            ->assertJsonPath('prompt.cueReading', 'かいしゃ')
            ->assertJsonPath('answer.expression', '会社')
            ->assertJsonPath('answer.meaning', 'company')
            ->assertJsonPath('state.queueState', 'new')
            ->assertJsonPath('state.source.noteId', null)
            ->assertJsonPath('answerAudioSource', 'missing');

        $deck = Deck::query()->sole();
        $this->assertSame($user->id, $deck->user_id);
        $this->assertSame(ResolveManualStudyDeckAction::DEFAULT_DECK_NAME, $deck->name);
        $this->assertSame(ResolveManualStudyDeckAction::DEFAULT_DECK_DESCRIPTION, $deck->description);
        $this->assertTrue($deck->is_manual_study_deck);

        $card = Card::query()->sole();
        $this->assertSame($deck->id, $card->deck_id);
        $this->assertSame('会社', $card->front_text);
        $this->assertSame('会社', $card->back_text);
        $this->assertSame(CardType::Recognition, $card->card_type);
        $this->assertSame(['cueText' => '会社', 'cueReading' => 'かいしゃ'], $card->prompt_json);
        $this->assertSame(['expression' => '会社', 'meaning' => 'company'], $card->answer_json);
        $this->assertSame('会社 会社 会社 かいしゃ 会社 company', $card->search_text);

        $entries = SyncFeedEntry::query()->orderBy('checkpoint')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('deck', $entries[0]->resource_type);
        $this->assertSame($deck->id, $entries[0]->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entries[0]->operation);
        $this->assertSame('card', $entries[1]->resource_type);
        $this->assertSame($card->id, $entries[1]->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entries[1]->operation);
        $this->assertSame(['cueText' => '会社', 'cueReading' => 'かいしゃ'], $entries[1]->payload['prompt_json']);
        $this->assertSame(['expression' => '会社', 'meaning' => 'company'], $entries[1]->payload['answer_json']);
    }

    public function test_it_reuses_the_existing_default_study_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user, [
            'name' => ResolveManualStudyDeckAction::DEFAULT_DECK_NAME,
            'description' => 'already here',
            'is_manual_study_deck' => true,
        ]);

        $this->postJson('/api/study/cards', [
            'cardType' => 'production',
            'prompt' => ['cueText' => 'company'],
            'answer' => ['meaning' => '会社'],
        ])
            ->assertCreated()
            ->assertJsonPath('cardType', 'production');

        $this->assertSame(1, Deck::query()->count());
        $this->assertTrue($deck->refresh()->is_manual_study_deck);
        $this->assertSame($deck->id, Card::query()->sole()->deck_id);
        $this->assertSame(1, SyncFeedEntry::query()->count());
        $this->assertSame('card', SyncFeedEntry::query()->sole()->resource_type);
    }

    public function test_it_accepts_a_client_provided_card_id_for_idempotent_retries(): void
    {
        $this->signIn();
        $id = strtolower((string) Str::ulid());

        $payload = [
            'id' => strtoupper($id),
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ];

        $firstResponse = $this->postJson('/api/study/cards', $payload);
        $secondResponse = $this->postJson('/api/study/cards', $payload);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('cardType', 'recognition')
            ->assertJsonPath('prompt.cueText', '会社')
            ->assertJsonPath('answer.meaning', 'company');

        $secondResponse
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('cardType', 'recognition')
            ->assertJsonPath('prompt.cueText', '会社')
            ->assertJsonPath('answer.meaning', 'company');

        $this->assertSame(1, Card::query()->count());
        $this->assertSame(1, Deck::query()->count());
        $this->assertSame(2, SyncFeedEntry::query()->count());
    }

    public function test_it_rate_limits_manual_card_creation_by_user(): void
    {
        $limiter = new StudyCardCreateRateLimiter;
        $clientIp = '127.0.0.1';
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

        $restoreStudyCardCreateLimiter = function () use ($limiter): void {
            RateLimiter::for(StudyCardCreateRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        RateLimiter::for(StudyCardCreateRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
            return Limit::perMinute(3)->by(
                $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
            );
        });

        try {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $this
                    ->postJson('/api/study/cards', [])
                    ->assertUnprocessable();
            }

            $this->signIn($otherUser);

            $this
                ->postJson('/api/study/cards', [])
                ->assertUnprocessable();

            $this->signIn($user);

            $this
                ->postJson('/api/study/cards', [])
                ->assertTooManyRequests();

            $this->assertSame(0, Card::query()->count());
        } finally {
            $restoreStudyCardCreateLimiter();
        }
    }

    public function test_it_rejects_client_provided_card_id_conflicts(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user, [
            'name' => ResolveManualStudyDeckAction::DEFAULT_DECK_NAME,
            'is_manual_study_deck' => true,
        ]);
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'old front',
            'back_text' => 'old back',
        ]);

        $this->postJson('/api/study/cards', [
            'id' => $id,
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'new front'],
            'answer' => ['meaning' => 'old back'],
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Card ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_id_conflict');

        $this->assertSame(1, Card::query()->count());
    }

    public function test_it_hides_cross_user_client_provided_card_id_conflicts(): void
    {
        $this->signIn();
        $id = strtolower((string) Str::ulid());

        Card::factory()
            ->for($this->deckFor(User::factory()->create()))
            ->create(['id' => $id]);

        $this->postJson('/api/study/cards', [
            'id' => $id,
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');

        $this->assertSame(1, Card::query()->count());
    }

    public function test_it_returns_gone_for_owned_deleted_client_provided_card_id_conflicts(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());
        $deletedCard = $this->cardFor($user, ['id' => $id]);

        $deletedCard->delete();

        $this->postJson('/api/study/cards', [
            'id' => $id,
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertStatus(410)
            ->assertJsonPath('message', 'Card ID belongs to a deleted card.')
            ->assertJsonPath('reason', 'card_deleted');

        $this->assertSame(1, Card::withTrashed()->count());
        $this->assertTrue($deletedCard->refresh()->trashed());
    }

    public function test_it_reuses_the_existing_default_study_deck_without_locking_the_user_row(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user, [
            'name' => ResolveManualStudyDeckAction::DEFAULT_DECK_NAME,
            'is_manual_study_deck' => true,
        ]);

        DB::enableQueryLog();

        $resolvedDeck = DB::transaction(
            fn (): Deck => app(ResolveManualStudyDeckAction::class)->handle($user->id),
        );

        $userQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'from "users"'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame($deck->id, $resolvedDeck->id);
        $this->assertSame(0, $userQueries);
    }

    public function test_manual_deck_resolution_requires_an_outer_transaction(): void
    {
        DB::partialMock()
            ->shouldReceive('transactionLevel')
            ->once()
            ->andReturn(0);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Manual study deck resolution must run inside an outer transaction.');

        app(ResolveManualStudyDeckAction::class)->handle(1);
    }

    public function test_it_does_not_reuse_a_regular_deck_with_the_default_name(): void
    {
        $user = $this->signIn();
        $regularDeck = $this->deckFor($user, [
            'name' => ResolveManualStudyDeckAction::DEFAULT_DECK_NAME,
            'is_manual_study_deck' => false,
        ]);

        $this->postJson('/api/study/cards', [
            'cardType' => 'production',
            'prompt' => ['cueText' => 'company'],
            'answer' => ['meaning' => '会社'],
        ])->assertCreated();

        $manualDeck = Deck::query()->where('is_manual_study_deck', true)->sole();
        $this->assertSame(2, Deck::query()->count());
        $this->assertFalse($regularDeck->refresh()->is_manual_study_deck);
        $this->assertSame($manualDeck->id, Card::query()->sole()->deck_id);
    }

    public function test_it_creates_the_card_inside_the_manual_deck_transaction(): void
    {
        $this->signIn();
        $transactionLevelDuringCardSync = 0;

        $this->app->bind(CreateCardAction::class, function () use (&$transactionLevelDuringCardSync): CreateCardAction {
            $recordSyncFeedEntry = new class($transactionLevelDuringCardSync) extends RecordSyncFeedEntryAction
            {
                private int $transactionLevelDuringCardSync;

                public function __construct(int &$transactionLevelDuringCardSync)
                {
                    $this->transactionLevelDuringCardSync = &$transactionLevelDuringCardSync;
                }

                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    if ($data->resourceType === 'card') {
                        $this->transactionLevelDuringCardSync = DB::transactionLevel();
                    }

                    return app(RecordSyncFeedEntryAction::class)->handle($data);
                }
            };

            return new CreateCardAction($recordSyncFeedEntry);
        });

        $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])->assertCreated();

        $this->assertGreaterThanOrEqual(2, $transactionLevelDuringCardSync);
    }

    public function test_it_starts_a_fresh_default_study_deck_when_the_previous_one_was_deleted(): void
    {
        $user = $this->signIn();
        $deletedDeck = $this->deckFor($user, [
            'name' => ResolveManualStudyDeckAction::DEFAULT_DECK_NAME,
            'is_manual_study_deck' => true,
        ]);

        app(DeleteDeckAction::class)->handle($deletedDeck);

        $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])->assertCreated();

        $activeDeck = Deck::query()->sole();
        $this->assertNotSame($deletedDeck->id, $activeDeck->id);
        $this->assertTrue($activeDeck->is_manual_study_deck);
        $this->assertSame(2, Deck::withTrashed()->count());
        $this->assertTrue(Deck::withTrashed()->findOrFail($deletedDeck->id)->trashed());
        $this->assertSame($activeDeck->id, Card::query()->sole()->deck_id);

        $entries = SyncFeedEntry::query()->orderBy('checkpoint')->get();
        $this->assertCount(3, $entries);
        $this->assertSame($deletedDeck->id, $entries[0]->resource_id);
        $this->assertSame(SyncFeedOperation::Delete, $entries[0]->operation);
        $this->assertSame($activeDeck->id, $entries[1]->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entries[1]->operation);
        $this->assertSame('card', $entries[2]->resource_type);
        $this->assertSame(SyncFeedOperation::Create, $entries[2]->operation);
    }

    public function test_it_derives_card_type_from_creation_kind_when_client_state_is_stale(): void
    {
        $this->signIn();

        $this->postJson('/api/study/cards', [
            'creationKind' => 'production-image',
            'cardType' => 'retired-card-type',
            'prompt' => ['cueText' => 'company'],
            'answer' => ['expression' => '会社', 'meaning' => 'company'],
        ])
            ->assertCreated()
            ->assertJsonPath('cardType', 'production');

        $this->assertSame(CardType::Production, Card::query()->sole()->card_type);
    }

    public function test_it_derives_card_type_from_creation_kind_without_card_type(): void
    {
        $this->signIn();

        $this->postJson('/api/study/cards', [
            'creationKind' => 'cloze',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertCreated()
            ->assertJsonPath('cardType', 'cloze');

        $this->assertSame(CardType::Cloze, Card::query()->sole()->card_type);
    }

    public function test_it_normalizes_card_type_and_payload_text_without_trim_strings_middleware(): void
    {
        $this->signIn();
        $id = strtolower((string) Str::ulid());

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/cards', [
                'id' => ' '.strtoupper($id).' ',
                'cardType' => ' PRODUCTION ',
                'prompt' => ['cueText' => '  会社  '],
                'answer' => ['meaning' => '  company  '],
            ])
            ->assertCreated()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('cardType', 'production')
            ->assertJsonPath('prompt.cueText', '  会社  ')
            ->assertJsonPath('answer.meaning', '  company  ');

        $card = Card::query()->sole();
        $this->assertSame($id, $card->id);
        $this->assertSame('会社', $card->front_text);
        $this->assertSame('company', $card->back_text);
        $this->assertSame(['cueText' => '  会社  '], $card->prompt_json);
        $this->assertSame(['meaning' => '  company  '], $card->answer_json);
    }

    public function test_it_validates_manual_card_payloads_and_type_fields(): void
    {
        $this->signIn();

        $this->postJson('/api/study/cards', [
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardType'])
            ->assertJsonPath('errors.cardType.0', 'cardType must be recognition, production, or cloze.');

        $this->postJson('/api/study/cards', [
            'cardType' => 'bad',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardType']);

        $this->postJson('/api/study/cards', [
            'creationKind' => 'bad',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['creationKind'])
            ->assertJsonPath('errors.creationKind.0', 'creationKind is not supported.');

        $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => ['cueAudio' => ['id' => 'media']],
            'answer' => ['answerAudio' => ['id' => 'media']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt', 'answer'])
            ->assertJsonPath('errors.prompt.0', 'prompt must include a non-empty text field.')
            ->assertJsonPath('errors.answer.0', 'answer must include a non-empty text field.');

        $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => ['cueText' => str_repeat('a', 25 * 1024)],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payloads']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/study/cards', [
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ])->assertUnauthorized();
    }
}
