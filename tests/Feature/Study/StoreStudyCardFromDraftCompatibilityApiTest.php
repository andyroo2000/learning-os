<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreStudyCardFromDraftCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_from_draft_requires_authentication(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create();

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", [
            'id' => strtolower((string) Str::ulid()),
        ])->assertUnauthorized();
    }

    public function test_it_creates_a_manual_study_card_from_a_ready_draft(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'creation_kind' => StudyCardCreationKind::ProductionText,
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);
        $cardId = strtolower((string) Str::ulid());

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", [
            'id' => strtoupper($cardId),
            'status' => 'client-owned',
        ])
            ->assertCreated()
            ->assertJsonPath('id', $cardId)
            ->assertJsonPath('cardType', CardType::Production->value)
            ->assertJsonPath('prompt.cueText', '会社')
            ->assertJsonPath('answer.meaning', 'company')
            ->assertJsonPath('state.queueState', 'new')
            ->assertJsonPath('answerAudioSource', 'missing');

        $card = Card::query()->sole();
        $this->assertSame($cardId, $card->id);
        $this->assertSame(['cueText' => '会社'], $card->prompt_json);
        $this->assertSame(['meaning' => 'company'], $card->answer_json);
        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $draft->id,
            'committed_card_id' => $cardId,
        ]);

        $this->getJson("/api/study/card-drafts/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('committedCardId', $cardId);
    }

    public function test_it_creates_a_manual_study_card_from_the_convolab_create_card_alias(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'creation_kind' => StudyCardCreationKind::TextRecognition,
            'prompt_json' => ['cueText' => '犬'],
            'answer_json' => ['meaning' => 'dog'],
        ]);
        $cardId = strtolower((string) Str::ulid());

        $this->postJson("/api/study/card-drafts/{$draft->id}/create-card", [
            'id' => strtoupper($cardId),
        ])
            ->assertCreated()
            ->assertJsonPath('id', $cardId)
            ->assertJsonPath('cardType', CardType::Recognition->value)
            ->assertJsonPath('prompt.cueText', '犬')
            ->assertJsonPath('answer.meaning', 'dog');

        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $draft->id,
            'committed_card_id' => $cardId,
        ]);

        $this->getJson("/api/study/card-drafts/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('committedCardId', $cardId);
    }

    public function test_it_normalizes_route_and_card_ids_without_trim_strings_middleware(): void
    {
        $draft = StudyCardDraft::factory()->ready()->for($this->signIn())->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);
        $cardId = strtolower((string) Str::ulid());

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/card-drafts/'.strtoupper($draft->id).'/card', [
                'id' => ' '.strtoupper($cardId).' ',
            ])
            ->assertCreated()
            ->assertJsonPath('id', $cardId);
    }

    public function test_it_is_idempotent_when_retried_with_the_same_card_id_and_draft_content(): void
    {
        $draft = StudyCardDraft::factory()->ready()->for($this->signIn())->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);
        $cardId = strtolower((string) Str::ulid());
        $payload = ['id' => $cardId];

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", $payload)
            ->assertCreated()
            ->assertJsonPath('id', $cardId);

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", $payload)
            ->assertOk()
            ->assertJsonPath('id', $cardId);

        $this->assertSame(1, Card::query()->count());
        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $draft->id,
            'committed_card_id' => $cardId,
        ]);
    }

    public function test_the_convolab_create_card_alias_shares_draft_commit_idempotency(): void
    {
        $draft = StudyCardDraft::factory()->ready()->for($this->signIn())->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);
        $cardId = strtolower((string) Str::ulid());
        $payload = ['id' => $cardId];

        $this->postJson("/api/study/card-drafts/{$draft->id}/create-card", $payload)
            ->assertCreated()
            ->assertJsonPath('id', $cardId);

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", $payload)
            ->assertOk()
            ->assertJsonPath('id', $cardId);

        $this->assertSame(1, Card::query()->count());
        $this->getJson("/api/study/card-drafts/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('committedCardId', $cardId);
    }

    public function test_draft_commit_idempotency_can_retry_from_canonical_path_to_convolab_alias(): void
    {
        $draft = StudyCardDraft::factory()->ready()->for($this->signIn())->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);
        $cardId = strtolower((string) Str::ulid());
        $payload = ['id' => $cardId];

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", $payload)
            ->assertCreated()
            ->assertJsonPath('id', $cardId);

        $this->postJson("/api/study/card-drafts/{$draft->id}/create-card", $payload)
            ->assertOk()
            ->assertJsonPath('id', $cardId);

        $this->assertSame(1, Card::query()->count());
        $this->getJson("/api/study/card-drafts/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('committedCardId', $cardId);
    }

    public function test_it_rejects_duplicate_commits_with_a_different_card_id(): void
    {
        $draft = StudyCardDraft::factory()->ready()->for($this->signIn())->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);
        $firstCardId = strtolower((string) Str::ulid());

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", [
            'id' => $firstCardId,
        ])->assertCreated();

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", [
            'id' => strtolower((string) Str::ulid()),
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Draft was already committed with a different card ID.');

        $this->assertSame(1, Card::query()->count());
        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $draft->id,
            'committed_card_id' => $firstCardId,
        ]);
    }

    public function test_the_convolab_create_card_alias_shares_different_card_id_conflicts(): void
    {
        $draft = StudyCardDraft::factory()->ready()->for($this->signIn())->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);
        $firstCardId = strtolower((string) Str::ulid());

        $this->postJson("/api/study/card-drafts/{$draft->id}/create-card", [
            'id' => $firstCardId,
        ])->assertCreated();

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", [
            'id' => strtolower((string) Str::ulid()),
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Draft was already committed with a different card ID.');

        $this->assertSame(1, Card::query()->count());
        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $draft->id,
            'committed_card_id' => $firstCardId,
        ]);
    }

    public function test_different_card_id_conflicts_can_retry_from_canonical_path_to_convolab_alias(): void
    {
        $draft = StudyCardDraft::factory()->ready()->for($this->signIn())->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);
        $firstCardId = strtolower((string) Str::ulid());

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", [
            'id' => $firstCardId,
        ])->assertCreated();

        $this->postJson("/api/study/card-drafts/{$draft->id}/create-card", [
            'id' => strtolower((string) Str::ulid()),
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Draft was already committed with a different card ID.');

        $this->assertSame(1, Card::query()->count());
        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $draft->id,
            'committed_card_id' => $firstCardId,
        ]);
    }

    public function test_it_requires_a_client_card_id_for_retry_safe_commits(): void
    {
        $draft = StudyCardDraft::factory()->ready()->for($this->signIn())->create();

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id'])
            ->assertJsonPath('errors.id.0', 'Card ID is required.');

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", ['id' => 'not-a-ulid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id'])
            ->assertJsonPath('errors.id.0', 'Card ID must be a valid ULID.');

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", ['id' => ['not-a-ulid']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id'])
            ->assertJsonPath('errors.id.0', 'Card ID must be a string.');
    }

    public function test_the_convolab_create_card_alias_requires_a_client_card_id_for_retry_safe_commits(): void
    {
        $draft = StudyCardDraft::factory()->ready()->for($this->signIn())->create();

        $this->postJson("/api/study/card-drafts/{$draft->id}/create-card", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id'])
            ->assertJsonPath('errors.id.0', 'Card ID is required.');
    }

    public function test_it_rejects_generating_drafts(): void
    {
        $draft = StudyCardDraft::factory()->for($this->signIn())->create();

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", [
            'id' => strtolower((string) Str::ulid()),
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Generating drafts cannot create cards yet.');

        $this->assertSame(0, Card::query()->count());
    }

    public function test_it_hides_cross_user_drafts_without_modifying_them(): void
    {
        $this->signIn();
        $otherDraft = StudyCardDraft::factory()->ready()->for(User::factory()->create())->create();

        $this->postJson("/api/study/card-drafts/{$otherDraft->id}/card", [
            'id' => strtolower((string) Str::ulid()),
        ])->assertNotFound();

        $this->assertSame(0, Card::query()->count());
        $this->assertDatabaseHas('study_card_drafts', ['id' => $otherDraft->id]);
    }

    public function test_it_hides_missing_drafts(): void
    {
        $this->signIn();

        $this->postJson('/api/study/card-drafts/'.strtolower((string) Str::ulid()).'/card', [
            'id' => strtolower((string) Str::ulid()),
        ])->assertNotFound();
    }

    public function test_it_returns_conflict_for_owned_card_id_metadata_mismatches(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create([
            'id' => strtolower((string) Str::ulid()),
            'front_text' => 'different',
            'back_text' => 'back',
        ]);

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", [
            'id' => $card->id,
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Card ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_id_conflict');
    }

    public function test_it_returns_gone_for_owned_deleted_card_id_conflicts(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create([
            'id' => strtolower((string) Str::ulid()),
        ]);
        $card->delete();

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", [
            'id' => $card->id,
        ])
            ->assertStatus(410)
            ->assertJsonPath('message', 'Card ID belongs to a deleted card.')
            ->assertJsonPath('reason', 'card_deleted');

        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $draft->id,
            'committed_card_id' => null,
        ]);
    }

    public function test_it_hides_cross_user_card_id_conflicts(): void
    {
        $draft = StudyCardDraft::factory()->ready()->for($this->signIn())->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);
        $otherUserCard = Card::factory()->create([
            'id' => strtolower((string) Str::ulid()),
        ]);

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", [
            'id' => $otherUserCard->id,
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');
    }

    public function test_it_rejects_drafts_without_card_text(): void
    {
        $draft = StudyCardDraft::factory()->ready()->for($this->signIn())->create([
            'prompt_json' => ['cueImage' => ['id' => 'image-1']],
            'answer_json' => ['answerImage' => ['id' => 'image-2']],
        ]);

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", [
            'id' => strtolower((string) Str::ulid()),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text'])
            ->assertJsonPath('errors.front_text.0', 'Card front text is required.');
    }

    public function test_it_rejects_drafts_without_back_text(): void
    {
        $draft = StudyCardDraft::factory()->ready()->for($this->signIn())->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['answerImage' => ['id' => 'image-2']],
        ]);

        $this->postJson("/api/study/card-drafts/{$draft->id}/card", [
            'id' => strtolower((string) Str::ulid()),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['back_text'])
            ->assertJsonPath('errors.back_text.0', 'Card back text is required.');
    }

    public function test_it_rate_limits_draft_commits_by_user(): void
    {
        $limiter = new StudyCardCreateRateLimiter;
        $clientIp = '127.0.0.1';
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $drafts = StudyCardDraft::factory()->ready()->for($user)->count(3)->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);
        $otherUser = User::factory()->create();
        $otherDraft = StudyCardDraft::factory()->ready()->for($otherUser)->create([
            'prompt_json' => ['cueText' => 'other front'],
            'answer_json' => ['meaning' => 'other back'],
        ]);

        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, $clientIp);
        $otherUserKey = $testBucket.'|'.$limiter->keyFor($otherUser->id, $clientIp);
        RateLimiter::clear($userKey);
        RateLimiter::clear($otherUserKey);

        $restoreStudyCardCreateLimiter = function () use ($limiter): void {
            RateLimiter::for(StudyCardCreateRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        try {
            $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

            RateLimiter::for(StudyCardCreateRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(2)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            $commitPaths = [
                "/api/study/card-drafts/{$drafts[0]->id}/card",
                "/api/study/card-drafts/{$drafts[1]->id}/create-card",
            ];

            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this
                    ->postJson($commitPaths[$attempt], [
                        'id' => strtolower((string) Str::ulid()),
                    ])
                    ->assertCreated();
            }

            $this
                ->postJson("/api/study/card-drafts/{$drafts[2]->id}/card", [
                    'id' => strtolower((string) Str::ulid()),
                ])
                ->assertTooManyRequests();

            $this->assertSame(2, Card::query()->count());

            $this->signIn($otherUser);

            $this
                ->postJson("/api/study/card-drafts/{$otherDraft->id}/card", [
                    'id' => strtolower((string) Str::ulid()),
                ])
                ->assertCreated();
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreStudyCardCreateLimiter();
        }
    }
}
