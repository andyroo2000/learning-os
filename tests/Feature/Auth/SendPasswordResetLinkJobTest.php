<?php

namespace Tests\Feature\Auth;

use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\TestCase;

class SendPasswordResetLinkJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_a_broker_notification_for_a_known_email(): void
    {
        config(['app.password_reset_url' => 'https://client.example/reset-password']);
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        (new SendPasswordResetLink('ada@example.com'))->handle();

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use ($user): bool {
                $mail = $notification->toMail($user);
                $query = parse_url($mail->actionUrl, PHP_URL_QUERY);

                $this->assertIsString($query);
                parse_str($query, $parameters);
                $this->assertSame('https://client.example/reset-password', strtok($mail->actionUrl, '?'));
                $this->assertSame($user->email, $parameters['email'] ?? null);
                $this->assertSame($notification->token, $parameters['token'] ?? null);

                return true;
            },
        );
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'ada@example.com']);
    }

    public function test_it_preserves_existing_reset_url_query_parameters(): void
    {
        config(['app.password_reset_url' => 'https://client.example/reset-password?source=email']);
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        (new SendPasswordResetLink('ada@example.com'))->handle();

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use ($user): bool {
                $query = parse_url($notification->toMail($user)->actionUrl, PHP_URL_QUERY);
                $this->assertIsString($query);
                parse_str($query, $parameters);

                return ($parameters['source'] ?? null) === 'email'
                    && ($parameters['email'] ?? null) === $user->email
                    && ($parameters['token'] ?? null) === $notification->token;
            },
        );
    }

    public function test_it_safely_ignores_an_unknown_email(): void
    {
        Notification::fake();

        (new SendPasswordResetLink('missing@example.com'))->handle();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_it_retries_transport_failures_after_the_broker_throttle_window(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $job = new SendPasswordResetLink('ada@example.com');
        Event::listen(NotificationSending::class, static function (): never {
            throw new RuntimeException('mail transport unavailable');
        });

        try {
            $job->handle();
            $this->fail('The transport failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('mail transport unavailable', $exception->getMessage());
        } finally {
            Event::forget(NotificationSending::class);
        }

        $failedTokenHash = DB::table('password_reset_tokens')->where('email', $user->email)->sole()->token;
        $this->assertIsString($failedTokenHash);
        $this->assertSame(3, $job->tries);
        $this->assertSame([65, 130], $job->backoff);
        $this->assertGreaterThan((int) config('auth.passwords.users.throttle'), $job->backoff[0]);

        Notification::fake();
        $this->travel(65)->seconds();
        try {
            $job->handle();
        } finally {
            $this->travelBack();
        }

        $retryTokenHash = DB::table('password_reset_tokens')->where('email', $user->email)->sole()->token;
        $this->assertNotSame($failedTokenHash, $retryTokenHash);
        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            fn (ResetPassword $notification): bool => Password::broker()->tokenExists($user, $notification->token),
        );
    }

    public function test_it_accepts_expected_non_delivery_broker_statuses(): void
    {
        Password::shouldReceive('sendResetLink')
            ->once()
            ->andReturn(Password::RESET_THROTTLED);

        (new SendPasswordResetLink('ada@example.com'))->handle();

        $this->addToAssertionCount(1);
    }

    public function test_it_normalizes_and_uniquely_keys_email_jobs(): void
    {
        $job = new SendPasswordResetLink(' ADA@example.com ');

        $this->assertSame('ada@example.com', $job->email);
        $this->assertSame(hash('sha256', 'ada@example.com'), $job->uniqueId());
        $this->assertSame(300, $job->uniqueFor);
    }
}
