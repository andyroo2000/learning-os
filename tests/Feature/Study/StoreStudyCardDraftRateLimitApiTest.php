<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Jobs\ProcessStudyCardDraft;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreStudyCardDraftRateLimitApiTest extends TestCase
{
    use RefreshDatabase;

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
