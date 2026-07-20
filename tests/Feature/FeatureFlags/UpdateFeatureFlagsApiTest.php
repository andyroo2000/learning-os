<?php

namespace Tests\Feature\FeatureFlags;

use App\Domain\FeatureFlags\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UpdateFeatureFlagsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_requires_authentication(): void
    {
        $this->patchJson('/api/feature-flags', [
            'dialoguesEnabled' => false,
        ])->assertUnauthorized();
    }

    public function test_update_route_uses_the_named_write_limiter(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/feature-flags'
                && in_array('PATCH', $route->methods(), true),
        );

        $this->assertNotNull($route);
        $this->assertContains('throttle:feature-flag-update', $route->gatherMiddleware());
    }

    public function test_update_rejects_an_ordinary_wildcard_mobile_token(): void
    {
        $token = User::factory()->create()
            ->createToken('convolab-proxy', ['*'])
            ->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/feature-flags', ['dialoguesEnabled' => false])
            ->assertForbidden();

        $this->assertDatabaseCount('feature_flags', 0);
    }

    public function test_update_rejects_a_non_proxy_token_with_the_write_scope(): void
    {
        $token = User::factory()->create()
            ->createToken('mobile', ['feature-flags:write'])
            ->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/feature-flags', ['dialoguesEnabled' => false])
            ->assertForbidden();

        $this->assertDatabaseCount('feature_flags', 0);
    }

    public function test_update_accepts_the_dedicated_proxy_write_scope(): void
    {
        Carbon::setTestNow('2026-07-20 18:15:12.345 UTC');
        $token = $this->proxyToken(['feature-flags:write']);

        $this->withToken($token)
            ->patchJson('/api/feature-flags', [
                'dialoguesEnabled' => false,
                'scriptsEnabled' => true,
                'audioCourseEnabled' => false,
                'flashcardsEnabled' => true,
            ])
            ->assertOk()
            ->assertExactJson([
                'id' => FeatureFlag::DEFAULT_ID,
                'dialoguesEnabled' => false,
                'scriptsEnabled' => true,
                'audioCourseEnabled' => false,
                'flashcardsEnabled' => true,
                'updatedAt' => '2026-07-20T18:15:12.345Z',
            ]);
    }

    public function test_update_temporarily_accepts_the_deployed_proxy_write_scope(): void
    {
        $token = $this->proxyToken(['study:read', 'study:write']);

        $this->withToken($token)
            ->patchJson('/api/feature-flags', ['flashcardsEnabled' => false])
            ->assertOk()
            ->assertJsonPath('flashcardsEnabled', false);
    }

    public function test_update_validates_each_known_flag_and_ignores_unknown_fields(): void
    {
        $token = $this->proxyToken(['feature-flags:write']);

        $this->withToken($token)
            ->patchJson('/api/feature-flags', [
                'dialoguesEnabled' => 'false',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dialoguesEnabled');

        $this->withToken($token)
            ->patchJson('/api/feature-flags', [
                'scriptsEnabled' => false,
                'internalValue' => true,
            ])
            ->assertOk()
            ->assertJsonMissingPath('internalValue')
            ->assertJsonPath('scriptsEnabled', false);
    }

    public function test_update_changes_the_same_latest_legacy_row_returned_by_show(): void
    {
        $token = $this->proxyToken(['feature-flags:write']);
        $older = $this->storeFlags('older', '2026-07-20 16:15:12.345 UTC', false);
        $latest = $this->storeFlags('latest', '2026-07-20 17:15:12.345 UTC', true);

        $this->withToken($token)
            ->patchJson('/api/feature-flags', ['scriptsEnabled' => false])
            ->assertOk()
            ->assertJsonPath('id', 'latest')
            ->assertJsonPath('scriptsEnabled', false);

        $this->assertFalse($older->refresh()->scriptsEnabled);
        $this->assertFalse($latest->refresh()->scriptsEnabled);
    }

    public function test_empty_update_returns_the_current_contract_without_changing_timestamp(): void
    {
        $token = $this->proxyToken(['feature-flags:write']);
        $featureFlags = $this->storeFlags('current', '2026-07-20 17:15:12.345 UTC', true);
        Carbon::setTestNow('2026-07-20 18:15:12.345 UTC');

        $this->withToken($token)
            ->patchJson('/api/feature-flags')
            ->assertOk()
            ->assertJsonPath('updatedAt', '2026-07-20T17:15:12.345Z');

        $this->assertSame(
            '2026-07-20 17:15:12.345',
            $featureFlags->refresh()->updatedAt->format('Y-m-d H:i:s.v'),
        );
    }

    /**
     * @param  list<string>  $abilities
     */
    private function proxyToken(array $abilities): string
    {
        return User::factory()->create()
            ->createToken('convolab-proxy', $abilities)
            ->plainTextToken;
    }

    private function storeFlags(
        string $id,
        string $timestamp,
        bool $scriptsEnabled,
    ): FeatureFlag {
        Carbon::setTestNow($timestamp);

        $featureFlags = new FeatureFlag([
            'dialoguesEnabled' => true,
            'scriptsEnabled' => $scriptsEnabled,
            'audioCourseEnabled' => true,
            'flashcardsEnabled' => true,
        ]);
        $featureFlags->id = $id;
        $featureFlags->save();

        return $featureFlags;
    }
}
