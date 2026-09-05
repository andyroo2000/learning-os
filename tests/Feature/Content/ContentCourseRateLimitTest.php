<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Support\ContentCourseRateLimiter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentCourseRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_write_routes_use_separate_named_rate_limiters(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ([
            ['POST', 'api/convolab/courses', ContentCourseRateLimiter::CREATE_NAME],
            ['PATCH', 'api/convolab/courses/{courseId}', ContentCourseRateLimiter::UPDATE_NAME],
            ['DELETE', 'api/convolab/courses/{courseId}', ContentCourseRateLimiter::DELETE_NAME],
        ] as [$method, $uri, $limiter]) {
            $route = $routes->first(fn ($candidate): bool => in_array($method, $candidate->methods(), true)
                && $candidate->uri() === $uri);
            $this->assertNotNull($route);
            $this->assertContains('throttle:'.$limiter, $route->gatherMiddleware());
        }
    }

    public function test_course_write_limiters_have_stable_separate_operation_scoped_user_keys(): void
    {
        $sourceUserId = (string) Str::uuid();
        $request = Request::create('/api/convolab/courses', 'POST');
        $authenticatedUser = new User;
        $authenticatedUser->setAttribute('id', 42);
        $authenticatedUser->setAttribute('convolab_id', strtoupper($sourceUserId));
        $request->setUserResolver(fn (): User => $authenticatedUser);

        $limiter = ContentCourseRateLimiter::forCreate();
        $limit = $limiter->limit($request);

        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame(
            ContentCourseRateLimiter::CREATE_NAME.':user:'.$sourceUserId,
            $limit->key,
        );

        $updateLimit = ContentCourseRateLimiter::forUpdate()->limit($request);
        $deleteLimit = ContentCourseRateLimiter::forDelete()->limit($request);
        $this->assertSame(60, $updateLimit->maxAttempts);
        $this->assertSame(30, $deleteLimit->maxAttempts);
        $this->assertSame(
            ContentCourseRateLimiter::UPDATE_NAME.':user:'.$sourceUserId,
            $updateLimit->key,
        );
        $this->assertSame(
            ContentCourseRateLimiter::DELETE_NAME.':user:'.$sourceUserId,
            $deleteLimit->key,
        );
        $this->assertNotSame($limit->key, $updateLimit->key);
        $this->assertNotSame($updateLimit->key, $deleteLimit->key);

        $authenticatedFallback = Request::create('/api/convolab/courses', 'POST');
        $authenticatedUser = new User;
        $authenticatedUser->setAttribute('id', 42);
        $authenticatedFallback->setUserResolver(fn (): User => $authenticatedUser);
        $this->assertSame(
            ContentCourseRateLimiter::CREATE_NAME.':user:42',
            $limiter->limit($authenticatedFallback)->key,
        );

        $anonymousFallback = Request::create(
            '/api/convolab/courses',
            'POST',
            server: ['REMOTE_ADDR' => '192.0.2.10'],
        );
        $this->assertSame(
            ContentCourseRateLimiter::CREATE_NAME.':anon:192.0.2.10',
            $limiter->limit($anonymousFallback)->key,
        );
    }
}
