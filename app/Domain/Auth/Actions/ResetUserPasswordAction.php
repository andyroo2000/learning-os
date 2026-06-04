<?php

namespace App\Domain\Auth\Actions;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetUserPasswordAction
{
    /**
     * @return string Password broker status code.
     */
    public function handle(string $email, string $token, string $password): string
    {
        // Keep actions defensive for non-HTTP callers; FormRequests normalize the API path too.
        return Password::reset([
            'email' => Str::lower(trim($email)),
            'token' => $token,
            'password' => $password,
            'password_confirmation' => $password,
        ], function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordResetEvent($user));

            // A password reset is account recovery, so revoke existing mobile bearer tokens.
            $user->tokens()->delete();
        });
    }
}
