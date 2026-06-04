<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Exceptions\DuplicateUserEmailException;
use App\Domain\Auth\Results\RegisterMobileUserResult;
use App\Domain\Auth\Support\MobileTokenExpiration;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class RegisterMobileUserAction
{
    public function __construct(private MobileTokenExpiration $mobileTokenExpiration) {}

    public function handle(string $name, string $email, string $password, string $deviceName): RegisterMobileUserResult
    {
        try {
            $user = User::create([
                'name' => trim($name),
                'email' => Str::lower(trim($email)),
                'password' => $password,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateUserEmailException;
        }

        $expiresAt = $this->mobileTokenExpiration->expiresAt();
        // Email verification is intentionally not required; clients use /api/me to branch on verification state.
        $token = $user->createToken(trim($deviceName), ['*'], $expiresAt);

        return new RegisterMobileUserResult(
            user: $user,
            plainTextToken: $token->plainTextToken,
            expiresAt: $expiresAt,
        );
    }
}
