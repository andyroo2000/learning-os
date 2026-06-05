<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class RegisterMobileUserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_a_user_and_issues_a_mobile_bearer_token(): void
    {
        $this->travelTo(Carbon::parse('2026-06-04 12:00:00'));
        $expirationMinutes = (int) config('sanctum.expiration');

        $response = $this->postJson('/api/auth/register', [
            'name' => ' Ada Lovelace ',
            'email' => ' ADA@example.com ',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'device_name' => ' Ada iPhone ',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.user.name', 'Ada Lovelace')
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonPath('data.user.email_verified_at', null)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.expires_at', now()->addMinutes($expirationMinutes)->toJSON())
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');

        $user = User::where('email', 'ada@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertNull($user->email_verified_at);

        $plainTextToken = $response->json('data.token');
        $token = PersonalAccessToken::findToken($plainTextToken);

        $this->assertNotNull($token);
        $this->assertTrue($token->tokenable->is($user));
        $this->assertSame('Ada iPhone', $token->name);
        $this->assertSame(['*'], $token->abilities);
        $this->assertSame(now()->addMinutes($expirationMinutes)->toJSON(), $token->expires_at?->toJSON());

        $this
            ->withToken($plainTextToken)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'ada@example.com');
    }

    public function test_it_returns_null_expiration_when_sanctum_expiration_is_disabled(): void
    {
        config(['sanctum.expiration' => null]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Dorothy Vaughan',
            'email' => 'dorothy@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'device_name' => 'Dorothy iPhone',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.expires_at', null);

        $token = PersonalAccessToken::findToken($response->json('data.token'));

        $this->assertNotNull($token);
        $this->assertNull($token->expires_at);
    }

    public function test_it_rejects_duplicate_email_after_normalization(): void
    {
        User::factory()->create([
            'email' => 'grace@example.com',
        ]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Grace Hopper',
            'email' => ' GRACE@example.com ',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'device_name' => 'Grace iPhone',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(1, User::where('email', 'grace@example.com')->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_it_normalizes_registration_fields_without_global_trim_middleware(): void
    {
        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/auth/register', [
                'name' => ' Katherine Johnson ',
                'email' => ' KATHERINE@example.com ',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'device_name' => ' Katherine iPad ',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.user.name', 'Katherine Johnson')
            ->assertJsonPath('data.user.email', 'katherine@example.com');

        $user = User::where('email', 'katherine@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Katherine Johnson', $user->name);

        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertNotNull($token);
        $this->assertSame('Katherine iPad', $token->name);
    }

    public function test_it_rate_limits_registration_attempts_by_email_and_ip(): void
    {
        User::factory()->create([
            'email' => 'throttle@example.com',
        ]);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this
                ->postJson('/api/auth/register', [
                    'name' => 'Throttle User',
                    'email' => 'throttle@example.com',
                    'password' => 'password123',
                    'password_confirmation' => 'password123',
                    'device_name' => 'Throttle iPhone',
                ])
                ->assertUnprocessable();
        }

        $this
            ->postJson('/api/auth/register', [
                'name' => 'Throttle User',
                'email' => 'throttle@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'device_name' => 'Throttle iPhone',
            ])
            ->assertTooManyRequests();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_it_validates_the_registration_payload(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
            'device_name' => ' ',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'email',
                'password',
                'device_name',
            ]);
    }
}
