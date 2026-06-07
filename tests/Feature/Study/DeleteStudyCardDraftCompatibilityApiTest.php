<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardDraftDeleteRateLimiter;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeleteStudyCardDraftCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_an_owned_study_card_draft(): void
    {
        $draft = StudyCardDraft::factory()->ready()->for($this->signIn())->create();

        $this->deleteJson('/api/study/card-drafts/'.strtoupper($draft->id))
            ->assertNoContent();

        $this->assertDatabaseMissing('study_card_drafts', [
            'id' => $draft->id,
        ]);
    }

    public function test_it_deletes_generating_drafts_to_clear_stuck_queue_items(): void
    {
        $draft = StudyCardDraft::factory()->for($this->signIn())->create();

        $this->deleteJson("/api/study/card-drafts/{$draft->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('study_card_drafts', [
            'id' => $draft->id,
        ]);
    }

    public function test_it_noops_missing_cross_user_and_already_deleted_drafts(): void
    {
        $user = $this->signIn();
        $otherUserDraft = StudyCardDraft::factory()->create();
        $deletedDraft = StudyCardDraft::factory()->for($user)->create();
        $deletedDraftId = $deletedDraft->id;
        $deletedDraft->delete();

        $this->deleteJson('/api/study/card-drafts/'.strtolower((string) Str::ulid()))
            ->assertNoContent();
        $this->deleteJson("/api/study/card-drafts/{$otherUserDraft->id}")
            ->assertNoContent();
        $this->deleteJson("/api/study/card-drafts/{$deletedDraftId}")
            ->assertNoContent();

        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $otherUserDraft->id,
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $draft = StudyCardDraft::factory()->create();

        $this->deleteJson("/api/study/card-drafts/{$draft->id}")
            ->assertUnauthorized();

        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $draft->id,
        ]);
    }

    public function test_it_rate_limits_manual_card_draft_deletes_by_user(): void
    {
        $limiter = new StudyCardDraftDeleteRateLimiter;
        $clientIp = '127.0.0.1';
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $otherUser = User::factory()->create();
        $previousServerVariables = $this->serverVariables;

        $restoreStudyCardDraftDeleteLimiter = function () use ($limiter): void {
            RateLimiter::for(StudyCardDraftDeleteRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, $clientIp);
        $otherUserKey = $testBucket.'|'.$limiter->keyFor($otherUser->id, $clientIp);
        RateLimiter::clear($userKey);
        RateLimiter::clear($otherUserKey);

        try {
            $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

            RateLimiter::for(StudyCardDraftDeleteRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(2)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this
                    ->deleteJson('/api/study/card-drafts/'.strtolower((string) Str::ulid()))
                    ->assertNoContent();
            }

            $this
                ->deleteJson('/api/study/card-drafts/'.strtolower((string) Str::ulid()))
                ->assertTooManyRequests();

            $this->signIn($otherUser);

            $this
                ->deleteJson('/api/study/card-drafts/'.strtolower((string) Str::ulid()))
                ->assertNoContent();
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreStudyCardDraftDeleteLimiter();
            $this->withServerVariables($previousServerVariables);
        }
    }
}
