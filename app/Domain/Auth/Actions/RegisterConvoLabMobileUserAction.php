<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Results\RegisterMobileUserResult;
use App\Domain\Auth\Support\MobileTokenExpiration;
use App\Models\User;
use LogicException;

final class RegisterConvoLabMobileUserAction
{
    public function __construct(
        private readonly RegisterConvoLabUserAction $registerConvoLabUser,
        private readonly MobileTokenExpiration $mobileTokenExpiration,
    ) {}

    public function handle(
        string $name,
        string $email,
        string $password,
        string $inviteCode,
        string $deviceName,
    ): RegisterMobileUserResult {
        $registration = $this->registerConvoLabUser->handle(
            email: $email,
            password: $password,
            name: $name,
            inviteCode: $inviteCode,
        );
        $user = User::query()->find($registration->account->user_id);
        if (! $user instanceof User) {
            throw new LogicException('ConvoLab registration did not resolve its credential user.');
        }

        $expiresAt = $this->mobileTokenExpiration->expiresAt();
        $token = $user->createToken(trim($deviceName), ['*'], $expiresAt);

        return new RegisterMobileUserResult(
            user: $user,
            plainTextToken: $token->plainTextToken,
            expiresAt: $expiresAt,
        );
    }
}
