<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Domain\Study\Support\StudyMediaGenerationRateLimiter;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class StudyMediaGenerationRateLimiterTest extends TestCase
{
    public function test_it_consumes_ten_attempts_and_rejects_the_eleventh(): void
    {
        $limiter = new StudyMediaGenerationRateLimiter;
        $key = $limiter->keyFor(42);
        RateLimiter::clear($key);

        try {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $limiter->consume(42);
            }

            $this->expectException(StudyPreviewMediaGenerationException::class);
            $this->expectExceptionMessage('Study media generation rate limit exceeded.');
            $limiter->consume(42);
        } finally {
            RateLimiter::clear($key);
        }
    }

    public function test_it_uses_a_stable_user_scoped_key(): void
    {
        $this->assertSame(
            'study-media-generation:user:42',
            (new StudyMediaGenerationRateLimiter)->keyFor(42),
        );
    }
}
