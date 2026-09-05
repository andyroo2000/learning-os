<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateStudyCardRateLimitApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @group no-parallel
     */
    public function test_it_rate_limits_study_card_updates_by_user(): void
    {
        $limiter = new StudyCardUpdateRateLimiter;
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

        $restoreStudyCardUpdateLimiter = function () use ($limiter): void {
            RateLimiter::for(StudyCardUpdateRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        // Authenticated keys ignore IP, so these match the request-derived keys used below.
        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, null);
        $otherUserKey = $testBucket.'|'.$limiter->keyFor($otherUser->id, null);

        try {
            // RateLimiter definitions are process-global; keep this sequential test out of parallel workers.
            RateLimiter::for(StudyCardUpdateRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(2)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $this
                    ->patchJson("/api/study/cards/{$card->id}", [
                        'prompt' => ['cueText' => "会社 {$attempt}"],
                        'answer' => ['meaning' => "company {$attempt}"],
                    ])
                    ->assertOk();
            }

            $this->signIn($otherUser);

            $this
                ->patchJson("/api/study/cards/{$otherCard->id}", [
                    'prompt' => ['cueText' => '学校 1'],
                    'answer' => ['meaning' => 'school 1'],
                ])
                ->assertOk();

            $this->signIn($user);

            $this
                ->patchJson("/api/study/cards/{$card->id}", [
                    'prompt' => ['cueText' => '会社 3'],
                    'answer' => ['meaning' => 'company 3'],
                ])
                ->assertTooManyRequests();

            $this->assertSame('会社 2', $card->refresh()->front_text);
            $this->assertSame('学校 1', $otherCard->refresh()->front_text);
            $this->assertSame(2, SyncFeedEntry::query()->where('user_id', $user->id)->count());
            $this->assertSame(1, SyncFeedEntry::query()->where('user_id', $otherUser->id)->count());
            $this->assertDatabaseMissing('sync_feed_entries', [
                'user_id' => $user->id,
                'payload->prompt_json->cueText' => '会社 3',
            ]);
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreStudyCardUpdateLimiter();
        }
    }
}
