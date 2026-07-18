<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\CreateStudyVocabBundleDraftsAction;
use App\Domain\Study\Data\CreateStudyVocabBundleData;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Domain\Study\Support\StudyCardDraftRetryRateLimiter;
use App\Jobs\ProcessStudyCardDraft;
use App\Jobs\ProcessStudyVocabBundleDrafts;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class RetryStudyCardDraftCompatibilityApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_retry_requires_authentication(): void
    {
        $draft = StudyCardDraft::factory()->failed()->create();

        $this->postJson("/api/study/card-drafts/{$draft->id}/retry")
            ->assertUnauthorized();
    }

    public function test_it_retries_an_owned_errored_manual_study_card_draft(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->failed()->for($user)->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['expression' => '会社', 'meaning' => 'company'],
            'image_placement' => StudyCardImagePlacement::Both,
            'image_prompt' => 'A company office',
            'preview_audio_json' => [
                'id' => 'audio-1',
                'filename' => 'kaisha.mp3',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            'preview_audio_role' => StudyCardAudioRole::Prompt,
            'preview_image_json' => [
                'id' => 'image-1',
                'filename' => 'kaisha.webp',
                'mediaKind' => 'image',
                'source' => 'generated',
            ],
            'error_message' => 'Generation failed.',
        ]);

        $response = $this->postJson('/api/study/card-drafts/'.strtoupper($draft->id).'/retry')
            ->assertOk()
            ->assertJsonPath('id', $draft->id)
            ->assertJsonPath('status', StudyManualCardDraftStatus::Generating->value)
            ->assertJsonPath('prompt.cueText', '会社')
            ->assertJsonPath('answer.meaning', 'company')
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::Both->value)
            ->assertJsonPath('imagePrompt', 'A company office')
            ->assertJsonPath('previewAudio', null)
            ->assertJsonPath('previewAudioRole', null)
            ->assertJsonPath('previewImage', null)
            ->assertJsonPath('errorMessage', null)
            ->assertJsonPath('committedCardId', null);

        $this->assertStudyCardDraftCompatibilityPayloadHasShape($response->json());

        $draft->refresh();
        $this->assertSame(StudyManualCardDraftStatus::Generating, $draft->status);
        $this->assertNull($draft->preview_audio_json);
        $this->assertNull($draft->preview_audio_role);
        $this->assertNull($draft->preview_image_json);
        $this->assertNull($draft->error_message);
        Queue::assertPushedOn(
            ProcessStudyCardDraft::QUEUE_NAME,
            ProcessStudyCardDraft::class,
            fn (ProcessStudyCardDraft $job): bool => $job->draftId === $draft->id,
        );
    }

    public function test_it_returns_generating_drafts_for_idempotent_transport_retries(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $generatingDraft = StudyCardDraft::factory()->for($user)->create();

        $response = $this->postJson("/api/study/card-drafts/{$generatingDraft->id}/retry")
            ->assertOk()
            ->assertJsonPath('id', $generatingDraft->id)
            ->assertJsonPath('status', StudyManualCardDraftStatus::Generating->value);

        $this->assertStudyCardDraftCompatibilityPayloadHasShape($response->json());

        Queue::assertPushedOn(
            ProcessStudyCardDraft::QUEUE_NAME,
            ProcessStudyCardDraft::class,
            fn (ProcessStudyCardDraft $job): bool => $job->draftId === $generatingDraft->id,
        );
    }

    public function test_manual_draft_retry_dispatch_failure_returns_an_error_draft(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->failed()->for($user)->create();
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));

        $this->postJson("/api/study/card-drafts/{$draft->id}/retry")
            ->assertOk()
            ->assertJsonPath('id', $draft->id)
            ->assertJsonPath('status', StudyManualCardDraftStatus::Error->value)
            ->assertJsonPath(
                'errorMessage',
                ProcessStudyCardDraft::EXHAUSTED_ERROR_MESSAGE,
            );

        $this->assertSame(StudyManualCardDraftStatus::Error, $draft->refresh()->status);
    }

    public function test_it_retries_an_owned_errored_vocab_bundle_as_one_job(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $group = $this->createVocabBundle($user);
        (new ProcessStudyVocabBundleDrafts($group->id))
            ->failed(new \RuntimeException('provider unavailable'));
        $draft = StudyCardDraft::query()
            ->where('variant_group_id', $group->id)
            ->firstOrFail();

        $response = $this->postJson("/api/study/card-drafts/{$draft->id}/retry")
            ->assertOk()
            ->assertJsonPath('id', $draft->id)
            ->assertJsonPath('status', StudyManualCardDraftStatus::Generating->value)
            ->assertJsonPath('errorMessage', null);

        $this->assertStudyCardDraftCompatibilityPayloadHasShape($response->json());
        $this->assertSame(
            11,
            StudyCardDraft::query()
                ->where('variant_group_id', $group->id)
                ->where('status', StudyManualCardDraftStatus::Generating)
                ->count(),
        );
        Queue::assertPushedOn(
            ProcessStudyVocabBundleDrafts::QUEUE_NAME,
            ProcessStudyVocabBundleDrafts::class,
            fn (ProcessStudyVocabBundleDrafts $job): bool => $job->groupId === $group->id,
        );
        Queue::assertNotPushed(ProcessStudyCardDraft::class);
    }

    public function test_vocab_bundle_retry_dispatch_failure_returns_an_error_draft(): void
    {
        $user = $this->signIn();
        $group = $this->createVocabBundle($user);
        (new ProcessStudyVocabBundleDrafts($group->id))
            ->failed(new \RuntimeException('provider unavailable'));
        $draft = StudyCardDraft::query()
            ->where('variant_group_id', $group->id)
            ->firstOrFail();
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));

        $this->postJson("/api/study/card-drafts/{$draft->id}/retry")
            ->assertOk()
            ->assertJsonPath('id', $draft->id)
            ->assertJsonPath('status', StudyManualCardDraftStatus::Error->value)
            ->assertJsonPath(
                'errorMessage',
                ProcessStudyVocabBundleDrafts::EXHAUSTED_ERROR_MESSAGE,
            );

        $this->assertSame(
            0,
            StudyCardDraft::query()
                ->where('variant_group_id', $group->id)
                ->where('status', StudyManualCardDraftStatus::Generating)
                ->count(),
        );
    }

    public function test_it_rejects_ready_drafts(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $readyDraft = StudyCardDraft::factory()->ready()->for($user)->create();

        $this->postJson("/api/study/card-drafts/{$readyDraft->id}/retry")
            ->assertConflict()
            ->assertJsonPath('message', 'Only errored drafts can be retried.');

        Queue::assertNothingPushed();
    }

    public function test_it_rejects_committed_drafts(): void
    {
        Queue::fake();
        $draft = StudyCardDraft::factory()->failed()->for($this->signIn())->create([
            'committed_card_id' => strtolower((string) Str::ulid()),
        ]);

        $this->postJson("/api/study/card-drafts/{$draft->id}/retry")
            ->assertConflict()
            ->assertJsonPath('message', 'Committed drafts cannot be retried.');

        $draft->refresh();
        $this->assertSame(StudyManualCardDraftStatus::Error, $draft->status);
        $this->assertNotNull($draft->error_message);
        Queue::assertNothingPushed();
    }

    public function test_it_hides_missing_and_cross_user_drafts(): void
    {
        Queue::fake();
        $this->signIn();
        $otherDraft = StudyCardDraft::factory()->failed()->for(User::factory()->create())->create();

        $this->postJson("/api/study/card-drafts/{$otherDraft->id}/retry")
            ->assertNotFound();

        $this->postJson('/api/study/card-drafts/'.strtolower((string) Str::ulid()).'/retry')
            ->assertNotFound();

        $this->assertSame(StudyManualCardDraftStatus::Error, $otherDraft->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_it_rate_limits_manual_card_draft_retries_by_user(): void
    {
        $limiter = new StudyCardDraftRetryRateLimiter;
        $clientIp = '127.0.0.1';
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $drafts = StudyCardDraft::factory()->failed()->for($user)->count(3)->create();
        $otherUser = User::factory()->create();
        $otherDraft = StudyCardDraft::factory()->failed()->for($otherUser)->create();
        $previousServerVariables = $this->serverVariables;

        $restoreStudyCardDraftRetryLimiter = function () use ($limiter): void {
            RateLimiter::for(StudyCardDraftRetryRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, $clientIp);
        $otherUserKey = $testBucket.'|'.$limiter->keyFor($otherUser->id, $clientIp);
        RateLimiter::clear($userKey);
        RateLimiter::clear($otherUserKey);

        try {
            $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

            RateLimiter::for(StudyCardDraftRetryRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(2)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this
                    ->postJson("/api/study/card-drafts/{$drafts[$attempt]->id}/retry")
                    ->assertOk();
            }

            $this
                ->postJson("/api/study/card-drafts/{$drafts[2]->id}/retry")
                ->assertTooManyRequests();

            $this->signIn($otherUser);

            $this
                ->postJson("/api/study/card-drafts/{$otherDraft->id}/retry")
                ->assertOk();
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreStudyCardDraftRetryLimiter();
            $this->withServerVariables($previousServerVariables);
        }
    }

    public function test_retry_rate_limiter_defaults_to_thirty_attempts_per_minute(): void
    {
        $request = Request::create('/api/study/card-drafts/'.strtolower((string) Str::ulid()).'/retry', 'POST');
        $request->server->set('REMOTE_ADDR', '203.0.113.10');

        $limit = (new StudyCardDraftRetryRateLimiter)->limit($request);

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('anon:203.0.113.10', $limit->key);
    }

    private function createVocabBundle(User $user): StudyVocabVariantGroup
    {
        return app(CreateStudyVocabBundleDraftsAction::class)->handle(
            CreateStudyVocabBundleData::fromInput(
                userId: $user->id,
                targetWord: '会社',
                sourceSentence: null,
                context: null,
                includeLearnerContext: false,
            ),
        )->group;
    }
}
