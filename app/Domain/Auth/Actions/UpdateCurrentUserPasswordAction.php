<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Exceptions\InvalidCurrentPasswordException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UpdateCurrentUserPasswordAction
{
    public function __construct(private readonly SetUserPasswordAction $setUserPassword) {}

    public function handle(User $user, string $currentPassword, string $password): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new InvalidCurrentPasswordException;
        }

        $this->setUserPassword->handle($user, $password);
    }
}
