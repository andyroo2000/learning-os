<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyCardPitchAccentRateLimiter;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class StudyCardPitchAccentRateLimiterTest extends TestCase
{
    public function test_it_uses_a_separate_user_bucket_with_the_pitch_accent_limit(): void
    {
        $request = Request::create('/api/study/cards/card/pitch-accent', 'POST');
        $user = new User;
        $user->id = 42;
        $request->setUserResolver(fn (): User => $user);

        $limit = (new StudyCardPitchAccentRateLimiter)->limit($request);

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame('study-card-pitch-accent:user:42', $limit->key);
    }

    public function test_it_uses_a_network_bucket_when_no_user_is_available(): void
    {
        $request = Request::create(
            '/api/study/cards/card/pitch-accent',
            'POST',
            server: ['REMOTE_ADDR' => '203.0.113.8'],
        );

        $limit = (new StudyCardPitchAccentRateLimiter)->limit($request);

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame('study-card-pitch-accent:anon:203.0.113.8', $limit->key);
    }
}
