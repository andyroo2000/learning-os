<?php

namespace App\Domain\Auth\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class SetUserPasswordAction
{
    public function handle(User $user, string $password, ?string $rememberToken = null): void
    {
        $passwordHash = Hash::make($password);
        $attributes = ['password' => $passwordHash];
        $compatibilityHash = $user->getAttribute('convolab_password_hash');

        if (is_string($compatibilityHash) && $compatibilityHash !== '') {
            $attributes['convolab_password_hash'] = $passwordHash;
        }

        if ($rememberToken !== null) {
            $attributes['remember_token'] = $rememberToken;
        }

        $user->forceFill($attributes)->save();
    }
}
