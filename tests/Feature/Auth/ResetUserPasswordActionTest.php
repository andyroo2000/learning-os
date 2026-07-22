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
        $this->assertNull($user->getAttribute('convolab_password_hash'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_it_replaces_the_convolab_compatibility_password_hash(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'old-password123',
        ]);
        $user->forceFill([
            'convolab_email_normalized' => 'ada@example.com',
            'convolab_password_hash' => Hash::make('old-password123'),
        ])->save();
        $resetToken = Password::broker()->createToken($user);

        $status = app(ResetUserPasswordAction::class)->handle(
            email: 'ada@example.com',
            token: $resetToken,
            password: 'new-password123',
        );

        $compatibilityHash = $user->refresh()->getAttribute('convolab_password_hash');

        $this->assertSame(Password::PASSWORD_RESET, $status);
        $this->assertIsString($compatibilityHash);
        $this->assertSame($user->password, $compatibilityHash);
        $this->assertFalse(password_verify('old-password123', $compatibilityHash));
        $this->assertTrue(password_verify('new-password123', $compatibilityHash));
        $this->assertFalse(Hash::check('old-password123', $user->password));
        $this->assertTrue(Hash::check('new-password123', $user->password));
    }
}
