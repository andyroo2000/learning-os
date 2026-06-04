<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\IssueMobileTokenAction;
use App\Domain\Auth\Exceptions\InvalidMobileTokenCredentialsException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class IssueMobileTokenActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_issues_a_mobile_token_for_valid_credentials(): void
    {
        $this->travelTo(Carbon::parse('2026-06-04 12:00:00'));
        $user = User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $result = app(IssueMobileTokenAction::class)->handle(
            email: ' ADA@example.com ',
            password: 'password',
            deviceName: ' Ada iPhone ',
        );

        $token = PersonalAccessToken::findToken($result->plainTextToken);

        $this->assertNotNull($token);
        $this->assertTrue($token->tokenable->is($user));
        $this->assertSame('Ada iPhone', $token->name);
        $this->assertSame(now()->addMinutes((int) config('sanctum.expiration'))->toJSON(), $result->expiresAt?->toJSON());
    }

    public function test_it_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $this->expectException(InvalidMobileTokenCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        app(IssueMobileTokenAction::class)->handle(
            email: 'ada@example.com',
            password: 'wrong-password',
            deviceName: 'Ada iPhone',
        );
    }

    public function test_it_rejects_unknown_email_without_creating_a_token(): void
    {
        try {
            app(IssueMobileTokenAction::class)->handle(
                email: 'missing@example.com',
                password: 'password',
                deviceName: 'Missing iPhone',
            );

            $this->fail('Expected invalid mobile token credentials exception was not thrown.');
        } catch (InvalidMobileTokenCredentialsException $exception) {
            $this->assertSame('Invalid credentials.', $exception->getMessage());
            $this->assertDatabaseCount('personal_access_tokens', 0);
        }
    }
}
