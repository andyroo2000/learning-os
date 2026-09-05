<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardDraftAutosaveRateLimiter;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateStudyCardDraftRateLimitApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rate_limits_manual_card_draft_autosaves_by_user(): void
    {
        $limiter = new StudyCardDraftAutosaveRateLimiter;
        $clientIp = '127.0.0.1';
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create();
        $otherUser = User::factory()->create();
        $previousServerVariables = $this->serverVariables;

        $restoreStudyCardDraftAutosaveLimiter = function () use ($limiter): void {
            RateLimiter::for(StudyCardDraftAutosaveRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, $clientIp);
        $otherUserKey = $testBucket.'|'.$limiter->keyFor($otherUser->id, $clientIp);
        RateLimiter::clear($userKey);
        RateLimiter::clear($otherUserKey);

        try {
            $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

            RateLimiter::for(StudyCardDraftAutosaveRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(2)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this
                    ->patchJson("/api/study/card-drafts/{$draft->id}", [
                        'imagePrompt' => 'Autosave '.$attempt,
                    ])
                    ->assertOk();
            }

            $this
                ->patchJson("/api/study/card-drafts/{$draft->id}", [
                    'imagePrompt' => 'Too fast',
                ])
                ->assertTooManyRequests();

            $this->signIn($otherUser);
            $otherDraft = StudyCardDraft::factory()->ready()->for($otherUser)->create();

            $this
                ->patchJson("/api/study/card-drafts/{$otherDraft->id}", [
                    'imagePrompt' => 'Other user bucket',
                ])
                ->assertOk();
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreStudyCardDraftAutosaveLimiter();
            $this->withServerVariables($previousServerVariables);
        }
    }
}
