<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\DailyAudioPracticeGenerationRateLimiter;
use Illuminate\Http\Request;
use Tests\TestCase;

class DailyAudioPracticeGenerationRateLimiterTest extends TestCase
{
    public function test_it_keys_authenticated_generation_by_user(): void
    {
        $limiter = new DailyAudioPracticeGenerationRateLimiter;
        $request = Request::create('/api/daily-audio-practice', 'POST');
        $request->setUserResolver(static fn (): object => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });
        $limit = $limiter->limit($request);

        $this->assertSame(10, $limit->maxAttempts);
        $this->assertSame(3_600, $limit->decaySeconds);
        $this->assertSame('daily-audio-practice-generation:user:42', $limit->key);
        $this->assertSame(
            'daily-audio-practice-generation:user:42',
            $limiter->keyFor(42, '203.0.113.10'),
        );
        $this->assertSame(
            'daily-audio-practice-generation:user:user-42',
            $limiter->keyFor('user-42', '203.0.113.10'),
        );
    }

    public function test_it_uses_a_defensive_network_fallback(): void
    {
        $limiter = new DailyAudioPracticeGenerationRateLimiter;

        $this->assertSame(
            'daily-audio-practice-generation:anon:203.0.113.10',
            $limiter->keyFor(null, '203.0.113.10'),
        );
        $this->assertSame(
            'daily-audio-practice-generation:anon:unknown-ip',
            $limiter->keyFor(0, null),
        );
    }
}
