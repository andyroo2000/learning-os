<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateStudyCardCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_a_study_card_from_prompt_and_answer_payloads(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T14:15:00Z'));

        try {
            $user = $this->signIn();
            $card = Card::factory()->for($this->deckFor($user))->create([
                'front_text' => 'old front',
                'back_text' => 'old back',
                'study_status' => CardStudyStatus::Review,
                'source_note_id' => 701,
                'source_card_id' => 901,
                'source_deck_id' => 301,
                'source_notetype_name' => 'Japanese - Vocab',
                'source_template_ord' => 1,
                'scheduler_state' => ['state' => 2],
            ]);

            $this->assertNull($card->prompt_json);
            $this->assertNull($card->answer_json);

            $response = $this->patchJson("/api/study/cards/{$card->id}", [
                'prompt' => [
                    'cueText' => '会社',
                    'cueReading' => 'かいしゃ',
                ],
                'answer' => [
                    'expression' => '会社',
                    'meaning' => 'company',
                ],
                'study_status' => 'new',
                'new_queue_position' => 99,
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('id', $card->id)
                ->assertJsonPath('noteId', '701')
                ->assertJsonPath('cardType', 'recognition')
                ->assertJsonPath('prompt.cueText', '会社')
                ->assertJsonPath('prompt.cueReading', 'かいしゃ')
                ->assertJsonPath('answer.expression', '会社')
                ->assertJsonPath('answer.meaning', 'company')
                ->assertJsonPath('state.queueState', 'review')
                ->assertJsonPath('state.scheduler.state', 2)
                ->assertJsonPath('state.source.noteId', '701')
                ->assertJsonPath('state.source.cardId', '901')
                ->assertJsonPath('state.source.deckId', '301')
                ->assertJsonPath('answerAudioSource', 'missing');

            $card->refresh();

            $this->assertSame('会社', $card->front_text);
            $this->assertSame('会社', $card->back_text);
            $this->assertSame('2026-06-05T14:15:00.000000Z', $card->updated_at?->toJSON());
            $this->assertSame(['cueText' => '会社', 'cueReading' => 'かいしゃ'], $card->prompt_json);
            $this->assertSame(['expression' => '会社', 'meaning' => 'company'], $card->answer_json);
            $this->assertSame('会社 会社 会社 かいしゃ 会社 company', $card->search_text);
            $this->assertSame(CardStudyStatus::Review, $card->study_status);

            $entry = SyncFeedEntry::query()->sole();
            $this->assertSame($user->id, $entry->user_id);
            $this->assertSame('flashcards', $entry->domain);
            $this->assertSame('card', $entry->resource_type);
            $this->assertSame($card->id, $entry->resource_id);
            $this->assertSame(SyncFeedOperation::Update, $entry->operation);
            $this->assertSame(['cueText' => '会社', 'cueReading' => 'かいしゃ'], $entry->payload['prompt_json']);
            $this->assertSame(['expression' => '会社', 'meaning' => 'company'], $entry->payload['answer_json']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_uses_fallback_payload_text_keys(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'old cloze',
            'back_text' => 'old answer',
        ]);

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => [
                'clozeDisplayText' => '彼は{{c1::学生}}です',
            ],
            'answer' => [
                'restoredText' => '彼は学生です',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('prompt.clozeDisplayText', '彼は{{c1::学生}}です')
            ->assertJsonPath('answer.restoredText', '彼は学生です');

        $card->refresh();

        $this->assertSame('彼は{{c1::学生}}です', $card->front_text);
        $this->assertSame('彼は学生です', $card->back_text);
    }

    public function test_it_returns_the_card_summary_when_the_update_is_unchanged(): void
    {
        $user = $this->signIn();
        $prompt = ['cueText' => '会社'];
        $answer = ['meaning' => 'company'];
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => '会社',
            'back_text' => 'company',
            'prompt_json' => $prompt,
            'answer_json' => $answer,
        ]);

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => $prompt,
            'answer' => $answer,
        ])
            ->assertOk()
            ->assertJsonPath('id', $card->id)
            ->assertJsonPath('prompt.cueText', '会社')
            ->assertJsonPath('answer.meaning', 'company');

        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_it_rate_limits_study_card_updates_by_user(): void
    {
        $limiter = new StudyCardUpdateRateLimiter;
        $clientIp = '127.0.0.1';
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'front_text' => '会社',
            'back_text' => 'company',
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser, [
            'front_text' => '学校',
            'back_text' => 'school',
            'prompt_json' => ['cueText' => '学校'],
            'answer_json' => ['meaning' => 'school'],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

        $restoreStudyCardUpdateLimiter = function () use ($limiter): void {
            RateLimiter::for(StudyCardUpdateRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, $clientIp);
        $otherUserKey = $testBucket.'|'.$limiter->keyFor($otherUser->id, $clientIp);
        RateLimiter::clear($userKey);
        RateLimiter::clear($otherUserKey);

        // RateLimiter definitions are process-global; keep this sequential test out of parallel workers.
        RateLimiter::for(StudyCardUpdateRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
            return Limit::perMinute(2)->by(
                $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
            );
        });

        try {
            $payload = [
                'prompt' => ['cueText' => '会社'],
                'answer' => ['meaning' => 'company'],
            ];

            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this
                    ->patchJson("/api/study/cards/{$card->id}", $payload)
                    ->assertOk();
            }

            $this->signIn($otherUser);

            $this
                ->patchJson("/api/study/cards/{$otherCard->id}", [
                    'prompt' => ['cueText' => '学校'],
                    'answer' => ['meaning' => 'school'],
                ])
                ->assertOk();

            $this->signIn($user);

            $this
                ->patchJson("/api/study/cards/{$card->id}", $payload)
                ->assertTooManyRequests();

            $this->assertDatabaseMissing('sync_feed_entries', [
                'user_id' => $user->id,
            ]);
            $this->assertDatabaseMissing('sync_feed_entries', [
                'user_id' => $otherUser->id,
            ]);
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreStudyCardUpdateLimiter();
        }
    }

    public function test_it_normalizes_route_id_and_payload_text_without_trim_strings_middleware(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'old front',
            'back_text' => 'old back',
        ]);

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->patchJson('/api/study/cards/'.strtoupper($card->id), [
                'prompt' => ['cueText' => '  会社  '],
                'answer' => ['meaning' => '  company  '],
            ])
            ->assertOk()
            ->assertJsonPath('prompt.cueText', '  会社  ')
            ->assertJsonPath('answer.meaning', '  company  ');

        $card->refresh();

        $this->assertSame('会社', $card->front_text);
        $this->assertSame('company', $card->back_text);
        $this->assertSame(['cueText' => '  会社  '], $card->prompt_json);
        $this->assertSame(['meaning' => '  company  '], $card->answer_json);
    }

    public function test_it_returns_not_found_for_missing_deleted_or_cross_user_cards(): void
    {
        $user = $this->signIn();
        $deletedCard = Card::factory()->for($this->deckFor($user))->create();
        $deletedDeck = $this->deckFor($user);
        $deletedDeckCard = Card::factory()->for($deletedDeck)->create();
        $otherUserCard = Card::factory()->for($this->deckFor(User::factory()->create()))->create();

        $deletedCard->delete();
        $deletedDeck->delete();

        $payload = [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ];

        $this->patchJson("/api/study/cards/{$deletedCard->id}", $payload)->assertNotFound();
        $this->patchJson("/api/study/cards/{$deletedDeckCard->id}", $payload)->assertNotFound();
        $this->patchJson("/api/study/cards/{$otherUserCard->id}", $payload)->assertNotFound();
        $this->patchJson('/api/study/cards/01HX0000000000000000000000', $payload)->assertNotFound();
    }

    public function test_it_validates_payloads(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create();

        $this->patchJson("/api/study/cards/{$card->id}", [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'prompt and answer payloads are required. (and 1 more error)');

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueAudio' => ['id' => 'media']],
            'answer' => ['answerAudio' => ['id' => 'media']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt', 'answer'])
            ->assertJsonPath('errors.prompt.0', 'prompt must include a non-empty text field.')
            ->assertJsonPath('errors.answer.0', 'answer must include a non-empty text field.');

        $tooDeep = 'too deep';
        // Eight wraps below prompt/answer nested fields reaches depth 9 from the payload root.
        for ($depth = 0; $depth < 8; $depth++) {
            $tooDeep = ['nested' => $tooDeep];
        }

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => 'front', 'nested' => $tooDeep],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt']);

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back', 'nested' => $tooDeep],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answer']);

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['nested' => $tooDeep],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt'])
            ->assertJsonCount(1, 'errors.prompt')
            ->assertJsonPath('errors.prompt.0', 'prompt must be 8 levels deep or fewer.');

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => str_repeat('a', 25 * 1024)],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payloads']);

        $this
            ->withHeaders(['Accept' => 'application/json'])
            // Use form encoding so the invalid UTF-8 byte reaches request validation.
            ->patch("/api/study/cards/{$card->id}", [
                'prompt' => ['cueText' => "\xB1"],
                'answer' => ['meaning' => 'back'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payloads'])
            ->assertJsonPath('errors.payloads.0', 'study card payloads contain invalid content.');
    }

    public function test_it_accepts_payloads_at_the_maximum_depth_boundary(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create();

        $maxDepth = 'at boundary';
        // Seven wraps below prompt/answer nested fields reaches depth 8 from the payload root.
        for ($depth = 0; $depth < 7; $depth++) {
            $maxDepth = ['nested' => $maxDepth];
        }

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => 'front', 'nested' => $maxDepth],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertOk()
            ->assertJsonPath('prompt.cueText', 'front')
            ->assertJsonPath('answer.meaning', 'back');

        $card->refresh();

        $this->assertSame('front', $card->front_text);
        $this->assertSame('back', $card->back_text);
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $this->patchJson("/api/study/cards/{$card->id}", [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ])->assertUnauthorized();
    }
}
