<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class StoreMobileTokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_issues_a_mobile_bearer_token(): void
    {
        $this->travelTo(Carbon::parse('2026-06-04 12:00:00'));
        $expirationMinutes = (int) config('sanctum.expiration');
        $user = User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $response = $this->postJson('/api/auth/tokens', [
            'email' => 'ada@example.com',
            'password' => 'password',
            'device_name' => 'Ada iPhone',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.expires_at', now()->addMinutes($expirationMinutes)->toJSON())
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'token_type',
                    'expires_at',
                ],
            ]);

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

    public function test_it_normalizes_email_and_device_name(): void
    {
        User::factory()->create([
            'email' => 'grace@example.com',
        ]);

        $response = $this->postJson('/api/auth/tokens', [
            'email' => ' GRACE@example.com ',
            'password' => 'password',
            'device_name' => ' Grace iPad ',
        ]);

        $response->assertCreated();

        $token = PersonalAccessToken::findToken($response->json('data.token'));

        $this->assertNotNull($token);
        $this->assertSame('Grace iPad', $token->name);
    }

    public function test_it_normalizes_email_and_device_name_without_global_trim_middleware(): void
    {
        User::factory()->create([
            'email' => 'grace@example.com',
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/auth/tokens', [
                'email' => ' GRACE@example.com ',
                'password' => 'password',
                'device_name' => ' Grace iPad ',
            ]);

        $response->assertCreated();

        $token = PersonalAccessToken::findToken($response->json('data.token'));

        $this->assertNotNull($token);
        $this->assertSame('Grace iPad', $token->name);
    }

    public function test_it_returns_null_expiration_when_sanctum_expiration_is_disabled(): void
    {
        config(['sanctum.expiration' => null]);
        User::factory()->create([
            'email' => 'dorothy@example.com',
        ]);

        $response = $this->postJson('/api/auth/tokens', [
            'email' => 'dorothy@example.com',
            'password' => 'password',
            'device_name' => 'Dorothy iPhone',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.expires_at', null);

        $token = PersonalAccessToken::findToken($response->json('data.token'));

        $this->assertNotNull($token);
        $this->assertNull($token->expires_at);
    }

    public function test_unverified_users_can_issue_mobile_tokens(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'katherine@example.com',
        ]);

        $response = $this->postJson('/api/auth/tokens', [
            'email' => 'katherine@example.com',
            'password' => 'password',
            'device_name' => 'Katherine iPhone',
        ]);

        $response->assertCreated();

        $this
            ->withToken($response->json('data.token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email_verified_at', null);
    }

    public function test_it_rejects_invalid_credentials_without_creating_a_token(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $response = $this->postJson('/api/auth/tokens', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
            'device_name' => 'Ada iPhone',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials.')
            ->assertJsonMissingPath('data.token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_it_rejects_unknown_email_without_creating_a_token(): void
    {
        $response = $this->postJson('/api/auth/tokens', [
            'email' => 'missing@example.com',
            'password' => 'password',
            'device_name' => 'Missing iPhone',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid credentials.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_it_rate_limits_token_attempts_by_email_and_ip(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this
                ->postJson('/api/auth/tokens', [
                    'email' => 'throttle@example.com',
                    'password' => 'wrong-password',
                    'device_name' => 'Throttle iPhone',
                ])
                ->assertUnauthorized();
        }

        $this
            ->postJson('/api/auth/tokens', [
                'email' => 'throttle@example.com',
                'password' => 'wrong-password',
                'device_name' => 'Throttle iPhone',
            ])
            ->assertTooManyRequests();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_it_validates_the_request_payload(): void
    {
        $response = $this->postJson('/api/auth/tokens', [
            'email' => 'not-an-email',
            'password' => '',
            'device_name' => ' ',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
                'password',
                'device_name',
            ]);
    }
}
