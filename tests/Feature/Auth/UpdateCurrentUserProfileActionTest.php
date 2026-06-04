<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\UpdateCurrentUserProfileAction;
use App\Domain\Auth\Exceptions\DuplicateUserEmailException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateCurrentUserProfileActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_a_users_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'email_verified_at' => now(),
        ]);

        $updatedUser = app(UpdateCurrentUserProfileAction::class)->handle(
            user: $user,
            name: ' Ada Lovelace ',
            email: ' ADA.LOVELACE@example.com ',
        );

        $this->assertTrue($updatedUser->is($user));
        $this->assertSame('Ada Lovelace', $updatedUser->name);
        $this->assertSame('ada.lovelace@example.com', $updatedUser->email);
        $this->assertNull($updatedUser->email_verified_at);
    }

    public function test_it_preserves_email_verification_when_email_is_unchanged(): void
    {
        $verifiedAt = now()->startOfSecond();
        $user = User::factory()->create([
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'email_verified_at' => $verifiedAt,
        ]);

        $updatedUser = app(UpdateCurrentUserProfileAction::class)->handle(
            user: $user,
            name: 'Amazing Grace',
            email: ' GRACE@example.com ',
        );

        $this->assertSame('Amazing Grace', $updatedUser->name);
        $this->assertSame($verifiedAt->toJSON(), $updatedUser->email_verified_at?->toJSON());
    }

    public function test_it_rejects_duplicate_email_after_normalization(): void
    {
        User::factory()->create([
            'email' => 'taken@example.com',
        ]);
        $user = User::factory()->create([
            'email' => 'ada@example.com',
        ]);

        $this->expectException(DuplicateUserEmailException::class);
        $this->expectExceptionMessage('The email has already been taken.');

        app(UpdateCurrentUserProfileAction::class)->handle(
            user: $user,
            name: 'Ada Lovelace',
            email: ' TAKEN@example.com ',
        );
    }
}
