<?php

namespace Tests\Unit\Achievements;

use App\Domain\Achievements\Support\AchievementEvaluationRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class AchievementEvaluationRateLimiterTest extends TestCase
{
    public function test_evaluation_uses_a_user_scoped_thirty_per_minute_bucket(): void
    {
        $request = Request::create(
            '/api/achievements/evaluate',
            'POST',
            server: ['REMOTE_ADDR' => '192.0.2.10'],
        );
        $request->setUserResolver(fn () => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });

        $limit = (new AchievementEvaluationRateLimiter)->limit($request);

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('achievement-evaluation:user:42', $limit->key);
    }

    public function test_evaluation_falls_back_to_a_typed_network_bucket(): void
    {
        $request = Request::create(
            '/api/achievements/evaluate',
            'POST',
            server: ['REMOTE_ADDR' => '192.0.2.10'],
        );

        $limit = (new AchievementEvaluationRateLimiter)->limit($request);

        $this->assertSame('achievement-evaluation:anon:192.0.2.10', $limit->key);
    }
}
