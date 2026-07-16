<?php

namespace Tests\Unit\Japanese;

use App\Domain\Japanese\Support\JapaneseKnowledgeRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JapaneseKnowledgeRateLimiterTest extends TestCase
{
    #[DataProvider('limiterProvider')]
    public function test_write_limiters_use_separate_per_user_buckets(
        JapaneseKnowledgeRateLimiter $limiter,
        string $expectedName,
        int $expectedAttempts,
    ): void {
        $request = Request::create('/api/study/known-kanji', 'POST', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.10',
        ]);
        $request->setUserResolver(fn () => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });

        $limit = $limiter->limit($request);

        $this->assertSame($expectedAttempts, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame("{$expectedName}:user:42", $limit->key);
    }

    /** @return array<string, array{JapaneseKnowledgeRateLimiter, string, int}> */
    public static function limiterProvider(): array
    {
        return [
            'connection' => [
                JapaneseKnowledgeRateLimiter::forConnection(),
                JapaneseKnowledgeRateLimiter::CONNECTION_NAME,
                10,
            ],
            'sync' => [
                JapaneseKnowledgeRateLimiter::forSync(),
                JapaneseKnowledgeRateLimiter::SYNC_NAME,
                6,
            ],
            'manual' => [
                JapaneseKnowledgeRateLimiter::forManual(),
                JapaneseKnowledgeRateLimiter::MANUAL_NAME,
                60,
            ],
        ];
    }
}
