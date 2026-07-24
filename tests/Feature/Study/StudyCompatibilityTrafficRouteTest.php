<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyCompatibilityTrafficRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_compatibility_route_has_the_network_limiter(): void
    {
        $routes = $this->compatibilityRoutes();
        $networkMiddleware = 'throttle:'.StudyCompatibilityTrafficRateLimiter::NETWORK_NAME;

        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $this->assertContains(
                $networkMiddleware,
                $route->gatherMiddleware(),
                $route->uri().' is missing the compatibility network limiter.',
            );
        }
    }

    public function test_compatibility_reads_use_read_or_media_buckets(): void
    {
        $readMiddleware = 'throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME;
        $mediaMiddleware = 'throttle:'.StudyCompatibilityTrafficRateLimiter::MEDIA_NAME;
        $mediaRoutes = [
            'api/daily-audio-practice/{practiceId}/tracks/{trackId}/audio',
            'api/study/media/{mediaAsset}',
        ];

        foreach ($this->compatibilityRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (! in_array('GET', $route->methods(), true)) {
                $this->assertNotContains($readMiddleware, $middleware, $route->uri());
                $this->assertNotContains($mediaMiddleware, $middleware, $route->uri());

                continue;
            }

            if (in_array($route->uri(), $mediaRoutes, true)) {
                $this->assertContains($mediaMiddleware, $middleware, $route->uri());
                $this->assertNotContains($readMiddleware, $middleware, $route->uri());
            } else {
                $this->assertContains($readMiddleware, $middleware, $route->uri());
                $this->assertNotContains($mediaMiddleware, $middleware, $route->uri());
            }
        }
    }

    public function test_all_three_named_limiters_are_registered(): void
    {
        foreach ([
            StudyCompatibilityTrafficRateLimiter::NETWORK_NAME,
            StudyCompatibilityTrafficRateLimiter::READ_NAME,
            StudyCompatibilityTrafficRateLimiter::MEDIA_NAME,
        ] as $name) {
            $this->assertNotNull(RateLimiter::limiter($name), $name);
        }
    }

    public function test_read_limiter_rejects_requests_after_the_actor_bucket_is_exhausted(): void
    {
        $testBucket = 'test-'.Str::ulid();
        $this->signIn();

        try {
            RateLimiter::for(
                StudyCompatibilityTrafficRateLimiter::NETWORK_NAME,
                static fn (Request $request): Limit => Limit::perMinute(10)
                    ->by($testBucket.'|network|'.$request->ip()),
            );
            RateLimiter::for(
                StudyCompatibilityTrafficRateLimiter::READ_NAME,
                static fn (Request $request): Limit => Limit::perMinute(1)
                    ->by($testBucket.'|read|'.$request->user()?->getAuthIdentifier()),
            );

            $this->getJson('/api/study/overview')->assertOk();
            $this->getJson('/api/study/overview')
                ->assertTooManyRequests()
                ->assertHeader('X-RateLimit-Limit', '1')
                ->assertHeader('X-RateLimit-Remaining', '0')
                ->assertHeader('Retry-After');
        } finally {
            $this->restoreCompatibilityLimiters();
        }
    }

    public function test_network_limiter_is_shared_across_authenticated_users(): void
    {
        $testBucket = 'test-'.Str::ulid();
        $firstUser = $this->signIn();
        $secondUser = User::factory()->create();

        try {
            RateLimiter::for(
                StudyCompatibilityTrafficRateLimiter::NETWORK_NAME,
                static fn (Request $request): Limit => Limit::perMinute(1)
                    ->by($testBucket.'|network|'.$request->ip()),
            );
            RateLimiter::for(
                StudyCompatibilityTrafficRateLimiter::READ_NAME,
                static fn (Request $request): Limit => Limit::perMinute(10)
                    ->by($testBucket.'|read|'.$request->user()?->getAuthIdentifier()),
            );

            $this->actingAs($firstUser)->getJson('/api/study/overview')->assertOk();
            $this->actingAs($secondUser)->getJson('/api/study/overview')
                ->assertTooManyRequests()
                ->assertHeader('X-RateLimit-Limit', '1')
                ->assertHeader('X-RateLimit-Remaining', '0')
                ->assertHeader('Retry-After');
        } finally {
            $this->restoreCompatibilityLimiters();
        }
    }

    /**
     * @return list<Route>
     */
    private function compatibilityRoutes(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => $route->uri() === 'api/study'
                || str_starts_with($route->uri(), 'api/study/')
                || $route->uri() === 'api/daily-audio-practice'
                || str_starts_with($route->uri(), 'api/daily-audio-practice/'))
            ->values()
            ->all();
    }

    private function restoreCompatibilityLimiters(): void
    {
        RateLimiter::for(
            StudyCompatibilityTrafficRateLimiter::NETWORK_NAME,
            StudyCompatibilityTrafficRateLimiter::networkLimit(...),
        );
        RateLimiter::for(
            StudyCompatibilityTrafficRateLimiter::READ_NAME,
            StudyCompatibilityTrafficRateLimiter::readLimit(...),
        );
        RateLimiter::for(
            StudyCompatibilityTrafficRateLimiter::MEDIA_NAME,
            StudyCompatibilityTrafficRateLimiter::mediaLimit(...),
        );
    }
}
