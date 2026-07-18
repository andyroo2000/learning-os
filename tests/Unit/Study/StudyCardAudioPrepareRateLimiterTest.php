<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyCardAudioPrepareRateLimiter;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class StudyCardAudioPrepareRateLimiterTest extends TestCase
{
    public function test_it_uses_a_separate_user_bucket_with_the_prepare_limit(): void
    {
        $request = Request::create('/api/study/cards/card/prepare-answer-audio', 'POST');
        $user = new User;
        $user->id = 42;
        $request->setUserResolver(fn (): User => $user);

        $limit = (new StudyCardAudioPrepareRateLimiter)->limit($request);

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame('study-card-audio-prepare:user:42', $limit->key);
    }
}
