<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\Exceptions\InvalidMobileTokenCredentialsException;
use App\Domain\Auth\Results\IssueMobileTokenResult;
use App\Domain\Auth\Support\MobileTokenExpiration;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class IssueMobileTokenAction
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$Ua0PWOoP5l5fnjhnZ00AnuYQhV5wl7iADVLPFNwuzN3VZ.g3RVJT2';

    public function __construct(private MobileTokenExpiration $mobileTokenExpiration) {}

    public function handle(string $email, string $password, string $deviceName): IssueMobileTokenResult
    {
        $email = Str::lower(trim($email));
        $deviceName = trim($deviceName);

        $user = User::where('email', $email)->first();

        if ($user === null) {
            // Keep unknown-email failures close to wrong-password failures for account enumeration timing.
            Hash::check($password, self::DUMMY_PASSWORD_HASH);

            throw new InvalidMobileTokenCredentialsException;
        }

        if (! Hash::check($password, $user->password)) {
            throw new InvalidMobileTokenCredentialsException;
        }

        $expiresAt = $this->mobileTokenExpiration->expiresAt();
        // Email verification is intentionally not required; clients use /api/me to branch on verification state.
        $token = $user->createToken($deviceName, ['*'], $expiresAt);

        return new IssueMobileTokenResult(
            plainTextToken: $token->plainTextToken,
            expiresAt: $expiresAt,
        );
    }
}
