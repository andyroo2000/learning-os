<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
                'errorMessage',
                'committedCardId',
                'createdAt',
                'updatedAt',
            ]);

        $draft = StudyCardDraft::query()->sole();
        $this->assertSame($user->id, $draft->user_id);
        $this->assertSame(StudyManualCardDraftStatus::Generating, $draft->status);
        $this->assertNull($draft->error_message);
    }

    public function test_it_defaults_and_normalizes_optional_fields_without_trim_strings_middleware(): void
    {
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
            ])
            ->assertCreated()
            ->assertJsonPath('creationKind', StudyCardCreationKind::ProductionImage->value)
            ->assertJsonPath('cardType', CardType::Production->value)
            ->assertJsonPath('prompt.cueText', '  company  ')
            ->assertJsonPath('answer.meaning', '  会社  ')
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::None->value)
            ->assertJsonPath('imagePrompt', null);

        $draft = StudyCardDraft::query()->sole();
        $this->assertSame(['cueText' => '  company  '], $draft->prompt_json);
        $this->assertSame(['meaning' => '  会社  '], $draft->answer_json);
        $this->assertNull($draft->image_prompt);

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
        $limiter = new StudyCardCreateRateLimiter;
        $clientIp = '127.0.0.1';
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $otherUser = User::factory()->create();

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
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreStudyCardCreateLimiter();
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
