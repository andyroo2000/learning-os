<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\ResetUserPasswordAction;
use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PasswordResetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_a_password_reset_link_without_exposing_token_secrets_in_the_response(): void
    {
        Queue::fake();
        Notification::fake();
        User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $response = $this->postJson('/api/auth/password/forgot', [
            'email' => ' ADA@example.com ',
        ]);

        $response
            ->assertNoContent()
            ->assertContent('');

        Queue::assertPushed(
            SendPasswordResetLink::class,
            fn (SendPasswordResetLink $job): bool => $job->email === 'ada@example.com',
        );
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_it_accepts_unknown_password_reset_emails_without_sending_notifications(): void
    {
        Queue::fake();
        Notification::fake();

        $response = $this->postJson('/api/auth/password/forgot', [
            'email' => 'missing@example.com',
        ]);

        $response->assertNoContent();
        Queue::assertPushed(
            SendPasswordResetLink::class,
            fn (SendPasswordResetLink $job): bool => $job->email === 'missing@example.com',
        );
        Notification::assertNothingSent();
    }

    public function test_it_normalizes_forgot_password_email_without_global_trim_middleware(): void
    {
        Queue::fake();
        User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/auth/password/forgot', [
                'email' => ' ADA@example.com ',
            ]);

        $response->assertNoContent();
        Queue::assertPushed(
            SendPasswordResetLink::class,
            fn (SendPasswordResetLink $job): bool => $job->email === 'ada@example.com',
        );
    }

    public function test_production_queue_dispatch_hides_lookup_and_email_delivery_from_the_http_request(): void
    {
        config(['queue.default' => 'database']);
        Notification::fake();
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/auth/password/forgot', [
            'email' => 'ada@example.com',
        ])->assertNoContent();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $payload = DB::table('jobs')->sole()->payload;
        $this->assertIsString($payload);
        $this->assertStringNotContainsString('ada@example.com', $payload);

        $this->postJson('/api/auth/password/forgot', [
            'email' => ' ADA@example.com ',
        ])->assertNoContent();
        $this->assertDatabaseCount('jobs', 1);
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

    public function test_it_normalizes_password_reset_email_without_global_trim_middleware(): void
    {
        Event::fake([PasswordResetEvent::class]);
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'old-password123',
        ]);
        $resetToken = Password::broker()->createToken($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/auth/password/reset', [
                'email' => ' ADA@example.com ',
                'token' => $resetToken,
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ]);

        $response->assertNoContent();

        $this->assertTrue(Hash::check('new-password123', $user->refresh()->password));
        Event::assertDispatched(PasswordResetEvent::class);
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
        Queue::fake();
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

    public function test_it_rate_limits_rotating_password_reset_emails_by_ip(): void
    {
        Queue::fake();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this
                ->postJson('/api/auth/password/forgot', [
                    'email' => "rotating-{$attempt}@example.com",
                ])
                ->assertNoContent();
        }

        $this
            ->postJson('/api/auth/password/forgot', [
                'email' => 'rotating-final@example.com',
            ])
            ->assertTooManyRequests();

        Queue::assertPushed(SendPasswordResetLink::class, 30);
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
