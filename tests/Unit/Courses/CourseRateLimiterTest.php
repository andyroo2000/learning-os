<?php

namespace Tests\Unit\Courses;

use App\Domain\Courses\Support\CourseRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CourseRateLimiterTest extends TestCase
{
    #[DataProvider('keyProvider')]
    public function test_it_builds_stable_user_and_network_keys(string $limiterName, mixed $userId, ?string $ip, string $expected): void
    {
        $this->assertSame($expected, CourseRateLimiter::keyFor($limiterName, $userId, $ip));
    }

    #[DataProvider('defaultLimiterProvider')]
    public function test_it_uses_expected_attempts_per_minute_by_default(
        CourseRateLimiter $limiter,
        string $method,
        string $uri,
        int $expectedAttempts,
        string $expectedKey,
    ): void {
        $request = Request::create($uri, $method, [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
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
        $this->assertSame($expectedKey, $limit->key);
    }

    /**
     * @return array<string, array{CourseRateLimiter, string, string, int, string}>
     */
    public static function defaultLimiterProvider(): array
    {
        return [
            'create' => [CourseRateLimiter::create(), 'POST', '/api/courses', 60, 'course-create:user:42'],
            'update' => [CourseRateLimiter::update(), 'PUT', '/api/courses/01HWZ1KCE7000000000000000', 60, 'course-update:user:42'],
            'delete' => [CourseRateLimiter::delete(), 'DELETE', '/api/courses/01HWZ1KCE7000000000000000', 30, 'course-delete:user:42'],
        ];
    }

    /**
     * @return array<string, array{string, mixed, string|null, string}>
     */
    public static function keyProvider(): array
    {
        return [
            'user id ignores localhost' => [CourseRateLimiter::CREATE_NAME, 42, '127.0.0.1', 'course-create:user:42'],
            'user id ignores public ip' => [CourseRateLimiter::CREATE_NAME, 42, '192.0.2.10', 'course-create:user:42'],
            'anonymous null ip' => [CourseRateLimiter::CREATE_NAME, null, null, 'course-create:anon:unknown-ip'],
            'anonymous empty ip' => [CourseRateLimiter::CREATE_NAME, null, '', 'course-create:anon:unknown-ip'],
            'anonymous localhost' => [CourseRateLimiter::CREATE_NAME, null, '127.0.0.1', 'course-create:anon:127.0.0.1'],
            'anonymous public ip' => [CourseRateLimiter::CREATE_NAME, null, '192.0.2.10', 'course-create:anon:192.0.2.10'],
            'string user id' => [CourseRateLimiter::CREATE_NAME, 'user-1', '', 'course-create:user:user-1'],
            'sentinel-looking string user id' => [CourseRateLimiter::CREATE_NAME, 'str-id', '', 'course-create:user:str-id'],
        ];
    }
}
