<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Exceptions\DuplicateUserEmailException;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class UpdateCurrentUserProfileAction
{
    public function handle(User $user, string $name, string $email): User
    {
        $email = Str::lower(trim($email));
        $emailChanged = $user->email !== $email;

        try {
            $user->forceFill([
                'name' => trim($name),
                'email' => $email,
                'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateUserEmailException;
        }

        return $user->refresh();
    }
}
