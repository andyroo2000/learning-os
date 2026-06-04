<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\ResetUserPasswordAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ResetUserPasswordActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resets_a_users_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'old-password123',
        ]);
        $user->createToken('Ada iPhone');
        $resetToken = Password::broker()->createToken($user);

        $status = app(ResetUserPasswordAction::class)->handle(
            email: ' ADA@example.com ',
            token: $resetToken,
            password: 'new-password123',
        );

        $this->assertSame(Password::PASSWORD_RESET, $status);
        $this->assertTrue(Hash::check('new-password123', $user->refresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
