<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyVocabBundleDraftRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class StudyVocabBundleDraftRateLimiterTest extends TestCase
{
    public function test_it_builds_operation_scoped_user_and_network_keys(): void
    {
        $limiter = new StudyVocabBundleDraftRateLimiter;

        $this->assertSame(
            'study-vocab-bundle-drafts:user:42',
            $limiter->keyFor(42, '127.0.0.1'),
        );
        $this->assertSame(
            'study-vocab-bundle-drafts:user:42',
            $limiter->keyFor(42, '192.0.2.10'),
        );
        $this->assertSame(
            'study-vocab-bundle-drafts:anon:unknown-ip',
            $limiter->keyFor(null, null),
        );
        $this->assertSame(
            'study-vocab-bundle-drafts:anon:127.0.0.1',
            $limiter->keyFor(null, '127.0.0.1'),
        );
    }

    public function test_it_uses_20_attempts_per_minute_by_default(): void
    {
        $limiter = new StudyVocabBundleDraftRateLimiter;
        $request = Request::create(
            '/api/study/card-candidates/vocab-bundle/drafts',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
        );
        $request->setUserResolver(fn () => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });

        $limit = $limiter->limit($request);

        $this->assertSame(20, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('study-vocab-bundle-drafts:user:42', $limit->key);
    }
}
