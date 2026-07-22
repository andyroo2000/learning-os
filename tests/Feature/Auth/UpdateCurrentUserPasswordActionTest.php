<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\UpdateCurrentUserPasswordAction;
use App\Domain\Auth\Exceptions\InvalidCurrentPasswordException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UpdateCurrentUserPasswordActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_a_users_password(): void
    {
        $user = User::factory()->create([
            'password' => 'old-password123',
        ]);

        app(UpdateCurrentUserPasswordAction::class)->handle(
            user: $user,
            currentPassword: 'old-password123',
            password: 'new-password123',
        );

        $this->assertTrue(Hash::check('new-password123', $user->refresh()->password));
        $this->assertNull($user->getAttribute('convolab_password_hash'));
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

        app(UpdateCurrentUserPasswordAction::class)->handle(
            user: $user,
            currentPassword: 'old-password123',
            password: 'new-password123',
        );

        $compatibilityHash = $user->refresh()->getAttribute('convolab_password_hash');

        $this->assertIsString($compatibilityHash);
        $this->assertSame($user->password, $compatibilityHash);
        $this->assertFalse(password_verify('old-password123', $compatibilityHash));
        $this->assertTrue(password_verify('new-password123', $compatibilityHash));
        $this->assertFalse(Hash::check('old-password123', $user->password));
        $this->assertTrue(Hash::check('new-password123', $user->password));
    }

    public function test_it_rejects_an_invalid_current_password(): void
    {
        $user = User::factory()->create([
            'password' => 'old-password123',
        ]);

        $this->expectException(InvalidCurrentPasswordException::class);
        $this->expectExceptionMessage('The current password is incorrect.');

        app(UpdateCurrentUserPasswordAction::class)->handle(
            user: $user,
            currentPassword: 'wrong-password',
            password: 'new-password123',
        );
    }
}
