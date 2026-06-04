<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\SendPasswordResetLinkAction;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class SendPasswordResetLinkActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_a_password_reset_notification_to_a_normalized_email(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $status = app(SendPasswordResetLinkAction::class)->handle(' ADA@example.com ');

        $this->assertSame(Password::RESET_LINK_SENT, $status);
        Notification::assertSentTo($user, ResetPassword::class);
    }
}
