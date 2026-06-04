<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Exceptions\InvalidCurrentPasswordException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UpdateCurrentUserPasswordAction
{
    public function handle(User $user, string $currentPassword, string $password): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new InvalidCurrentPasswordException;
        }

        $user->forceFill([
            'password' => $password,
        ])->save();
    }
}
