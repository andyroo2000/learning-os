<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Support\AuthAccountRateLimiter;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Auth\Concerns\UsesAuthAccountRateLimitOverrides;
use Tests\TestCase;

class UpdateCurrentUserProfileApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesAuthAccountRateLimitOverrides;

    public function test_it_updates_the_authenticated_users_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'email_verified_at' => now(),
        ]);
        $token = $user->createToken('Ada iPhone')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->putJson('/api/me', [
                'name' => ' Ada Lovelace ',
                'email' => ' ADA.LOVELACE@example.com ',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Ada Lovelace')
            ->assertJsonPath('data.email', 'ada.lovelace@example.com')
            ->assertJsonPath('data.email_verified_at', null)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $user->refresh();

        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('ada.lovelace@example.com', $user->email);
        $this->assertNull($user->email_verified_at);

        $this
            ->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'ada.lovelace@example.com');
    }

    public function test_it_preserves_email_verification_when_email_is_unchanged(): void
    {
        $verifiedAt = now()->startOfSecond();
        $user = User::factory()->create([
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'email_verified_at' => $verifiedAt,
        ]);
        $token = $user->createToken('Grace iPhone')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->putJson('/api/me', [
                'name' => 'Amazing Grace',
                'email' => ' GRACE@example.com ',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'Amazing Grace')
            ->assertJsonPath('data.email', 'grace@example.com')
            ->assertJsonPath('data.email_verified_at', $verifiedAt->toJSON());

        $this->assertSame($verifiedAt->toJSON(), $user->refresh()->email_verified_at?->toJSON());
    }

    public function test_it_rate_limits_profile_updates_by_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
        ]);
        $token = $user->createToken('Ada iPhone')->plainTextToken;
        $otherUser = User::factory()->create([
            'email' => 'grace@example.com',
        ]);
        $otherToken = $otherUser->createToken('Grace iPhone')->plainTextToken;

        $this->withAuthAccountRateLimitOverride(
            AuthAccountRateLimiter::PROFILE_UPDATE,
            [$user->id, $otherUser->id],
            function () use ($otherToken, $otherUser, $token, $user): void {
                foreach ([1, 2] as $attempt) {
                    $this
                        ->withToken($token)
                        ->putJson('/api/me', [
                            'name' => "Ada {$attempt}",
                            'email' => "ada-{$attempt}@example.com",
                        ])
                        ->assertOk()
                        ->assertJsonPath('data.name', "Ada {$attempt}")
                        ->assertJsonPath('data.email', "ada-{$attempt}@example.com");
                }

                $this->app['auth']->forgetGuards();

                $this
                    ->withToken($otherToken)
                    ->putJson('/api/me', [
                        'name' => 'Grace Hopper',
                        'email' => 'grace.hopper@example.com',
                    ])
                    ->assertOk()
                    ->assertJsonPath('data.id', $otherUser->id);

                $this->app['auth']->forgetGuards();

                $this
                    ->withToken($token)
                    ->putJson('/api/me', [
                        'name' => 'Blocked Ada',
                        'email' => 'blocked-ada@example.com',
                    ])
                    ->assertTooManyRequests()
                    ->assertJsonPath('message', 'Too Many Attempts.')
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->withToken($token)
                    ->getJson('/api/me')
                    ->assertOk()
                    ->assertJsonPath('data.name', 'Ada 2')
                    ->assertJsonPath('data.email', 'ada-2@example.com');

                $user->refresh();
                $this->assertSame('Ada 2', $user->name);
                $this->assertSame('ada-2@example.com', $user->email);
                $this->assertDatabaseMissing('users', [
                    'id' => $user->id,
                    'email' => 'blocked-ada@example.com',
                ]);
            },
        );
    }

    public function test_it_rejects_duplicate_email_after_normalization(): void
    {
        User::factory()->create([
            'email' => 'taken@example.com',
        ]);
        $user = User::factory()->create([
            'email' => 'ada@example.com',
        ]);
        $token = $user->createToken('Ada iPhone')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->putJson('/api/me', [
                'name' => 'Ada Lovelace',
                'email' => ' TAKEN@example.com ',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $user->refresh();

        $this->assertSame('ada@example.com', $user->email);
    }

    public function test_it_normalizes_profile_fields_without_global_trim_middleware(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'email_verified_at' => now(),
        ]);
        $token = $user->createToken('Ada iPhone')->plainTextToken;

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->withToken($token)
            ->putJson('/api/me', [
                'name' => ' Ada Lovelace ',
                'email' => ' ADA.LOVELACE@example.com ',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'Ada Lovelace')
            ->assertJsonPath('data.email', 'ada.lovelace@example.com')
            ->assertJsonPath('data.email_verified_at', null);

        $user->refresh();

        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('ada.lovelace@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_it_validates_the_profile_payload(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Ada iPhone')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->putJson('/api/me', [
                'name' => '',
                'email' => 'not-an-email',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'email',
            ]);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->putJson('/api/me', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        $response->assertUnauthorized();
    }
}
