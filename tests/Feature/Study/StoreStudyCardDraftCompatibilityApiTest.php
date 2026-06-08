<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Study\Sync\StudyCardDraftSyncPayload;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Http\Requests\Study\StoreStudyCardDraftRequest;
use App\Jobs\ProcessStudyCardDraft;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Feature\Study\Concerns\BuildsStudyCardDraftRows;
use Tests\TestCase;

class StoreStudyCardDraftCompatibilityApiTest extends TestCase
{
    use BuildsStudyCardDraftRows;
    use RefreshDatabase;

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'cloze',
            'cardType' => 'cloze',
            'prompt' => ['clozeText' => '試合に[勝ちました]。'],
            'answer' => [],
        ])->assertUnauthorized();
    }

    public function test_it_creates_a_manual_study_card_draft(): void
    {
        Queue::fake();
        $user = $this->signIn();

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'cloze',
            'cardType' => 'cloze',
            'prompt' => ['clozeText' => '試合に[勝ちました]。'],
            'answer' => [],
            'imagePlacement' => 'both',
            'imagePrompt' => null,
            'status' => 'ready',
            'errorMessage' => 'client-owned',
        ])
            ->assertCreated()
            ->assertJsonPath('status', StudyManualCardDraftStatus::Generating->value)
            ->assertJsonPath('creationKind', StudyCardCreationKind::Cloze->value)
            ->assertJsonPath('cardType', CardType::Cloze->value)
            ->assertJsonPath('prompt.clozeText', '試合に[勝ちました]。')
            ->assertJsonPath('answer', [])
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::Both->value)
            ->assertJsonPath('imagePrompt', null)
            ->assertJsonPath('previewAudio', null)
            ->assertJsonPath('previewAudioRole', null)
            ->assertJsonPath('previewImage', null)
            ->assertJsonPath('variantGroupId', null)
            ->assertJsonPath('variantSentenceId', null)
            ->assertJsonPath('variantKind', null)
            ->assertJsonPath('variantStage', null)
            ->assertJsonPath('variantStatus', null)
            ->assertJsonPath('variantUnlockedAt', null)
            ->assertJsonPath('errorMessage', null)
            ->assertJsonPath('committedCardId', null)
            ->assertJsonStructure([
                'id',
                'status',
                'creationKind',
                'cardType',
                'prompt',
                'answer',
                'imagePlacement',
                'imagePrompt',
                'previewAudio',
                'previewAudioRole',
                'previewImage',
                'variantGroupId',
                'variantSentenceId',
                'variantKind',
                'variantStage',
                'variantStatus',
                'variantUnlockedAt',
                'errorMessage',
                'committedCardId',
                'createdAt',
                'updatedAt',
            ]);

        $draft = StudyCardDraft::query()->sole();
        $this->assertSame($user->id, $draft->user_id);
        $this->assertSame(StudyManualCardDraftStatus::Generating, $draft->status);
        $this->assertNull($draft->error_message);
        Queue::assertPushedOn(
            ProcessStudyCardDraft::QUEUE_NAME,
            ProcessStudyCardDraft::class,
            fn (ProcessStudyCardDraft $job): bool => $job->draftId === $draft->id,
        );
    }

    public function test_it_creates_a_manual_study_card_draft_with_variant_metadata(): void
    {
        Queue::fake();
        $user = $this->signIn();

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/card-drafts', [
                'creationKind' => ' text-recognition ',
                'cardType' => ' recognition ',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantGroupId' => ' vocab-group-1 ',
                'variantSentenceId' => ' sentence-1 ',
                'variantKind' => ' SENTENCE_AUDIO_RECOGNITION ',
                'variantStage' => ' +2 ',
                'variantStatus' => ' AVAILABLE ',
                'variantUnlockedAt' => '2026-06-04T14:15:30.987654Z',
            ])
            ->assertCreated()
            ->assertJsonPath('variantGroupId', 'vocab-group-1')
            ->assertJsonPath('variantSentenceId', 'sentence-1')
            ->assertJsonPath('variantKind', VocabVariantKind::SentenceAudioRecognition->value)
            ->assertJsonPath('variantStage', 2)
            ->assertJsonPath('variantStatus', VocabVariantStatus::Available->value)
            // The storage column is second-precision, so fractional input is normalized away.
            ->assertJsonPath('variantUnlockedAt', '2026-06-04T14:15:30.000000Z');

        $draft = StudyCardDraft::query()->sole();
        $this->assertSame($user->id, $draft->user_id);
        $this->assertSame('vocab-group-1', $draft->variant_group_id);
        $this->assertSame('sentence-1', $draft->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceAudioRecognition->value, $draft->variant_kind);
        $this->assertSame(2, $draft->variant_stage);
        $this->assertSame(VocabVariantStatus::Available->value, $draft->variant_status);
        $this->assertSame('2026-06-04T14:15:30.000000Z', $draft->variant_unlocked_at?->toJSON());

        $entry = SyncFeedEntry::query()->sole();
        $this->assertSame(StudyCardDraftSyncPayload::fromDraft($draft), $entry->payload);

        Queue::assertPushed(ProcessStudyCardDraft::class);
    }

    public function test_it_accepts_unsigned_string_variant_stage_without_trim_strings_middleware(): void
    {
        Queue::fake();
        $this->signIn();

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/card-drafts', [
                'creationKind' => 'text-recognition',
                'cardType' => 'recognition',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantStage' => ' 2 ',
            ])
            ->assertCreated()
            ->assertJsonPath('variantStage', 2);

        $draft = StudyCardDraft::query()->sole();
        $this->assertSame(2, $draft->variant_stage);

        $entry = SyncFeedEntry::query()->sole();
        $this->assertSame(StudyCardDraftSyncPayload::fromDraft($draft), $entry->payload);

        Queue::assertPushed(ProcessStudyCardDraft::class);
    }

    public function test_request_treats_timezone_naive_variant_unlock_timestamps_as_utc(): void
    {
        $previousTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('America/New_York');

            $request = StoreStudyCardDraftRequest::create('/api/study/card-drafts', 'POST', [
                'creationKind' => 'text-recognition',
                'cardType' => 'recognition',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantUnlockedAt' => '2026-06-04T14:15:30',
            ]);
            $request->setContainer($this->app)->setRedirector($this->app['redirect']);
            $request->validateResolved();

            $this->assertSame('2026-06-04T14:15:30.000000Z', $request->variantUnlockedAt()?->toJSON());

            $offsetRequest = StoreStudyCardDraftRequest::create('/api/study/card-drafts', 'POST', [
                'creationKind' => 'text-recognition',
                'cardType' => 'recognition',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantUnlockedAt' => '2026-06-04T14:15:30+05:30',
            ]);
            $offsetRequest->setContainer($this->app)->setRedirector($this->app['redirect']);
            $offsetRequest->validateResolved();

            $this->assertSame('2026-06-04T08:45:30.000000Z', $offsetRequest->variantUnlockedAt()?->toJSON());

            $fractionalNaiveRequest = StoreStudyCardDraftRequest::create('/api/study/card-drafts', 'POST', [
                'creationKind' => 'text-recognition',
                'cardType' => 'recognition',
                'prompt' => ['cueText' => '犬'],
                'answer' => ['meaning' => 'dog'],
                'variantUnlockedAt' => '2026-06-04T14:15:30.987654',
            ]);
            $fractionalNaiveRequest->setContainer($this->app)->setRedirector($this->app['redirect']);
            $fractionalNaiveRequest->validateResolved();

            $this->assertSame('2026-06-04T14:15:30.987654Z', $fractionalNaiveRequest->variantUnlockedAt()?->toJSON());
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function test_it_defaults_and_normalizes_optional_fields_without_trim_strings_middleware(): void
    {
        Queue::fake();
        $this->signIn();

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/card-drafts', [
                'creationKind' => ' PRODUCTION-IMAGE ',
                'cardType' => ' PRODUCTION ',
                'prompt' => ['cueText' => '  company  '],
                'answer' => ['meaning' => '  会社  '],
                'imagePlacement' => null,
                'imagePrompt' => '   ',
                'variantGroupId' => '   ',
                'variantSentenceId' => "\t",
                'variantKind' => '   ',
                'variantStage' => null,
                'variantStatus' => "\n",
                'variantUnlockedAt' => '   ',
            ])
            ->assertCreated()
            ->assertJsonPath('creationKind', StudyCardCreationKind::ProductionImage->value)
            ->assertJsonPath('cardType', CardType::Production->value)
            ->assertJsonPath('prompt.cueText', '  company  ')
            ->assertJsonPath('answer.meaning', '  会社  ')
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::None->value)
            ->assertJsonPath('imagePrompt', null)
            ->assertJsonPath('variantGroupId', null)
            ->assertJsonPath('variantSentenceId', null)
            ->assertJsonPath('variantKind', null)
            ->assertJsonPath('variantStage', null)
            ->assertJsonPath('variantStatus', null)
            ->assertJsonPath('variantUnlockedAt', null);

        $draft = StudyCardDraft::query()->sole();
        $this->assertSame(['cueText' => '  company  '], $draft->prompt_json);
        $this->assertSame(['meaning' => '  会社  '], $draft->answer_json);
        $this->assertNull($draft->image_prompt);
        $this->assertNull($draft->variant_group_id);
        $this->assertNull($draft->variant_sentence_id);
        $this->assertNull($draft->variant_kind);
        $this->assertNull($draft->variant_stage);
        $this->assertNull($draft->variant_status);
        $this->assertNull($draft->variant_unlocked_at);

        // This test intentionally posts twice: first for payload normalization, then for defaults.
        StudyCardDraft::query()->delete();

        $this
            ->postJson('/api/study/card-drafts', [
                'creationKind' => 'text-recognition',
                'cardType' => 'recognition',
                'prompt' => ['cueText' => 'front'],
                'answer' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::None->value);

        Queue::assertPushed(ProcessStudyCardDraft::class, 2);
    }

    public function test_it_validates_card_type_payload_and_image_fields(): void
    {
        $this->signIn();

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'cloze',
            'cardType' => 'recognition',
            'prompt' => ['clozeText' => '試合に[勝ちました]。'],
            'answer' => ['meaning' => 'won'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cardType'])
            ->assertJsonPath('errors.cardType.0', 'cardType must match creationKind.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'bad',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['creationKind'])
            ->assertJsonPath('errors.creationKind.0', 'creationKind is not supported.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => 'front',
            'answer' => ['meaning' => 'back'],
            'imagePlacement' => 'sideways',
            'imagePrompt' => str_repeat('a', 1001),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt', 'imagePlacement', 'imagePrompt'])
            ->assertJsonPath('errors.prompt.0', 'prompt and answer payloads are required.')
            ->assertJsonPath('errors.imagePlacement.0', 'imagePlacement must be none, prompt, answer, or both.')
            ->assertJsonPath('errors.imagePrompt.0', 'imagePrompt must be 1000 characters or fewer.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => null,
            'answer' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt'])
            ->assertJsonPath('errors.prompt.0', 'prompt and answer payloads are required.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => str_repeat('a', 25 * 1024)],
            'answer' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payloads'])
            ->assertJsonPath('errors.payloads.0', 'study card payloads must be 24 KB or smaller.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
            'imagePrompt' => ['not' => 'a string'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['imagePrompt']);

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => [['cueText' => 'front']],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt'])
            ->assertJsonPath('errors.prompt.0', 'prompt and answer payloads are required.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => [['meaning' => 'back']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answer'])
            ->assertJsonPath('errors.answer.0', 'prompt and answer payloads are required.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
            'variantGroupId' => str_repeat('a', 65),
            'variantSentenceId' => str_repeat('b', 65),
            'variantKind' => 'sentence-audio-recognition',
            'variantStage' => 0,
            'variantStatus' => 'unknown',
            'variantUnlockedAt' => 'not-a-date',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'variantGroupId',
                'variantSentenceId',
                'variantKind',
                'variantStage',
                'variantStatus',
                'variantUnlockedAt',
            ])
            ->assertJsonPath('errors.variantGroupId.0', 'variantGroupId must be 64 characters or fewer.')
            ->assertJsonPath('errors.variantSentenceId.0', 'variantSentenceId must be 64 characters or fewer.')
            ->assertJsonPath('errors.variantKind.0', 'variantKind is not supported.')
            ->assertJsonPath('errors.variantStage.0', 'variantStage must be between 1 and 65535.')
            ->assertJsonPath('errors.variantStatus.0', 'variantStatus is not supported.')
            ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a valid timestamp.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
            'variantGroupId' => ['vocab-group-1'],
            'variantSentenceId' => ['sentence-1'],
            'variantKind' => ['sentence_cloze'],
            'variantStage' => ['2'],
            'variantStatus' => ['available'],
            'variantUnlockedAt' => ['2026-06-04T14:15:30Z'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'variantGroupId',
                'variantSentenceId',
                'variantKind',
                'variantStage',
                'variantStatus',
                'variantUnlockedAt',
            ])
            ->assertJsonPath('errors.variantGroupId.0', 'variantGroupId must be a string.')
            ->assertJsonPath('errors.variantSentenceId.0', 'variantSentenceId must be a string.')
            ->assertJsonPath('errors.variantKind.0', 'variantKind must be a string.')
            ->assertJsonPath('errors.variantStage.0', 'variantStage must be an integer.')
            ->assertJsonPath('errors.variantStatus.0', 'variantStatus must be a string.')
            ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a string.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
            'variantUnlockedAt' => 1234567890,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variantUnlockedAt'])
            ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a string.');

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
            'variantUnlockedAt' => 'yesterday',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variantUnlockedAt'])
            ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a valid timestamp.');
    }

    public function test_it_returns_conflict_when_the_user_draft_queue_is_full(): void
    {
        $user = $this->signIn();
        $this->insertCappedDraftRowsFor($user);

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '犬'],
            'answer' => [],
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Draft queue is full. Delete some drafts before adding more.');
    }

    public function test_it_rate_limits_manual_card_draft_creation_by_user(): void
    {
        Queue::fake();
        $limiter = new StudyCardCreateRateLimiter;
        $clientIp = '127.0.0.1';
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $otherUser = User::factory()->create();
        $previousServerVariables = $this->serverVariables;

        $restoreStudyCardCreateLimiter = function () use ($limiter): void {
            RateLimiter::for(StudyCardCreateRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, $clientIp);
        $otherUserKey = $testBucket.'|'.$limiter->keyFor($otherUser->id, $clientIp);
        RateLimiter::clear($userKey);
        RateLimiter::clear($otherUserKey);

        try {
            $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

            RateLimiter::for(StudyCardCreateRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(3)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $this
                    ->postJson('/api/study/card-drafts', $this->draftCreatePayload('front '.$attempt))
                    ->assertCreated();
            }

            $this->signIn($otherUser);

            $this
                ->postJson('/api/study/card-drafts', $this->draftCreatePayload('other user'))
                ->assertCreated();

            $this->signIn($user);

            $this
                ->postJson('/api/study/card-drafts', $this->draftCreatePayload('blocked'))
                ->assertTooManyRequests();

            $this->assertSame(4, StudyCardDraft::query()->count());
            Queue::assertPushed(ProcessStudyCardDraft::class, 4);
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreStudyCardCreateLimiter();
            $this->withServerVariables($previousServerVariables);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function draftCreatePayload(string $cueText): array
    {
        return [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => $cueText],
            'answer' => ['meaning' => 'back'],
        ];
    }
}
