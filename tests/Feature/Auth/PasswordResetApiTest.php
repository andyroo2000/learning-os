<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\ResetUserPasswordAction;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_a_password_reset_link_without_exposing_token_secrets_in_the_response(): void
    {
        config(['app.password_reset_url' => 'https://client.example/reset-password']);
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $response = $this->postJson('/api/auth/password/forgot', [
            'email' => ' ADA@example.com ',
        ]);

        $response
            ->assertNoContent()
            ->assertContent('');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $query = parse_url($mail->actionUrl, PHP_URL_QUERY);

            $this->assertIsString($query);
            parse_str($query, $parameters);

            $this->assertSame('https://client.example/reset-password', strtok($mail->actionUrl, '?'));
            $this->assertSame('ada@example.com', $parameters['email'] ?? null);
            $this->assertSame($notification->token, $parameters['token'] ?? null);

            return true;
        });
    }

    public function test_it_appends_password_reset_parameters_to_configured_reset_url_queries(): void
    {
        config(['app.password_reset_url' => 'https://client.example/reset-password?source=email']);
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $this->postJson('/api/auth/password/forgot', [
            'email' => 'ada@example.com',
        ])->assertNoContent();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $query = parse_url($mail->actionUrl, PHP_URL_QUERY);

            $this->assertIsString($query);
            parse_str($query, $parameters);

            $this->assertSame('https://client.example/reset-password', strtok($mail->actionUrl, '?'));
            $this->assertSame('email', $parameters['source'] ?? null);
            $this->assertSame('ada@example.com', $parameters['email'] ?? null);
            $this->assertSame($notification->token, $parameters['token'] ?? null);

            return true;
        });
    }

    public function test_it_accepts_unknown_password_reset_emails_without_sending_notifications(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/password/forgot', [
            'email' => 'missing@example.com',
        ]);

        $response->assertNoContent();
        Notification::assertNothingSent();
    }

    public function test_it_resets_a_password_and_revokes_existing_mobile_tokens(): void
    {
        Event::fake([PasswordResetEvent::class]);
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'old-password123',
        ]);
        $oldToken = $user->createToken('Ada iPhone')->plainTextToken;
        $resetToken = Password::broker()->createToken($user);

        $response = $this->postJson('/api/auth/password/reset', [
            'email' => ' ADA@example.com ',
            'token' => $resetToken,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response->assertNoContent();

        $this->assertTrue(Hash::check('new-password123', $user->refresh()->password));
        $this->assertFalse(Password::broker()->tokenExists($user, $resetToken));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Event::assertDispatched(PasswordResetEvent::class);

        $this->app['auth']->forgetGuards();

        $this
            ->withToken($oldToken)
            ->getJson('/api/me')
            ->assertUnauthorized();

        $this
            ->postJson('/api/auth/tokens', [
                'email' => 'ada@example.com',
                'password' => 'new-password123',
                'device_name' => 'Ada iPad',
            ])
            ->assertCreated();
    }

    public function test_it_rejects_invalid_reset_tokens_without_updating_the_password(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'old-password123',
        ]);
        $user->createToken('Ada iPhone');

        $response = $this->postJson('/api/auth/password/reset', [
            'email' => 'ada@example.com',
            'token' => 'not-the-token',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token'])
            ->assertJsonPath('errors.token.0', __('passwords.token'));

        $this->assertTrue(Hash::check('old-password123', $user->refresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_it_returns_too_many_requests_when_the_password_broker_throttles_reset_attempts(): void
    {
        $this->mock(ResetUserPasswordAction::class, function ($mock): void {
            $mock
                ->shouldReceive('handle')
                ->once()
                ->andReturn(Password::RESET_THROTTLED);
        });

        $this
            ->postJson('/api/auth/password/reset', [
                'email' => 'ada@example.com',
                'token' => 'valid-looking-token',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ])
            ->assertTooManyRequests()
            ->assertJson([
                'message' => __(Password::RESET_THROTTLED),
            ]);
    }

    public function test_it_rejects_unknown_reset_emails_without_account_specific_errors(): void
    {
        $response = $this->postJson('/api/auth/password/reset', [
            'email' => 'missing@example.com',
            'token' => 'not-the-token',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token'])
            ->assertJsonMissingValidationErrors(['email'])
            ->assertJsonPath('errors.token.0', __('passwords.token'));
    }

    public function test_it_validates_password_reset_payloads(): void
    {
        $response = $this->postJson('/api/auth/password/reset', [
            'email' => 'not-an-email',
            'token' => '',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
                'token',
                'password',
            ]);
    }

    public function test_it_rate_limits_password_reset_link_requests_by_email_and_ip(): void
    {
        Notification::fake();

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this
                ->postJson('/api/auth/password/forgot', [
                    'email' => 'throttle@example.com',
                ])
                ->assertNoContent();
        }

        $this
            ->postJson('/api/auth/password/forgot', [
                'email' => 'throttle@example.com',
            ])
            ->assertTooManyRequests();

        $this
            ->postJson('/api/auth/password/reset', [
                'email' => 'throttle@example.com',
                'token' => 'not-the-token',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);

        Notification::assertNothingSent();
    }

    public function test_it_rate_limits_password_reset_token_requests_by_email_and_ip(): void
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $this
                ->postJson('/api/auth/password/reset', [
                    'email' => 'throttle@example.com',
                    'token' => 'not-the-token',
                    'password' => 'new-password123',
                    'password_confirmation' => 'new-password123',
                ])
                ->assertUnprocessable();
        }

        $this
            ->postJson('/api/auth/password/reset', [
                'email' => 'throttle@example.com',
                'token' => 'not-the-token',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ])
            ->assertTooManyRequests();
    }
}
