<?php

namespace App\Domain\Auth\Actions;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetUserPasswordAction
{
    public function __construct(private readonly SetUserPasswordAction $setUserPassword) {}

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
            $this->setUserPassword->handle($user, $password, Str::random(60));

            event(new PasswordResetEvent($user));

            // A password reset is account recovery, so revoke existing mobile bearer tokens.
            $user->tokens()->delete();
        });
    }
}
