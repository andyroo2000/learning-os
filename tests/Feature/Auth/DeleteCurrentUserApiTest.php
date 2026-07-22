<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Support\AuthAccountRateLimiter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Feature\Auth\Concerns\UsesAuthAccountRateLimitOverrides;
use Tests\TestCase;

class DeleteCurrentUserApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesAuthAccountRateLimitOverrides;

    public function test_it_deletes_the_authenticated_account(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Ada iPhone')->plainTextToken;

        $this->withToken($token)
            ->deleteJson('/api/me')
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_it_requires_authentication(): void
    {
        $this->deleteJson('/api/me')->assertUnauthorized();
    }

    public function test_it_enforces_the_account_delete_rate_limiter_before_deleting(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Ada iPhone')->plainTextToken;

        $this->withAuthAccountRateLimitOverride(
            AuthAccountRateLimiter::ACCOUNT_DELETE,
            [$user->id],
            function () use ($token, $user): void {
                User::deleting(function (): never {
                    throw new RuntimeException('Keep the account available for the limiter retry.');
                });

                $this->withToken($token)
                    ->deleteJson('/api/me')
                    ->assertInternalServerError();

                $this->app['auth']->forgetGuards();

                $this->withToken($token)
                    ->deleteJson('/api/me')
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '1')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this->assertDatabaseHas('users', ['id' => $user->id]);
            },
            perMinute: 1,
        );
    }

    public function test_the_route_uses_the_account_delete_rate_limiter(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/me' && in_array('DELETE', $route->methods(), true));

        $this->assertNotNull($route);
        $this->assertContains(
            'throttle:'.AuthAccountRateLimiter::ACCOUNT_DELETE,
            $route->gatherMiddleware(),
        );
    }
}
