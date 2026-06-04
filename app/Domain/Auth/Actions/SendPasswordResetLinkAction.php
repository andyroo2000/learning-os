<?php

namespace App\Domain\Auth\Actions;

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class SendPasswordResetLinkAction
{
    /**
     * @return string Password broker status code.
     */
    public function handle(string $email): string
    {
        // Keep actions defensive for non-HTTP callers; FormRequests normalize the API path too.
        return Password::sendResetLink([
            'email' => Str::lower(trim($email)),
        ]);
    }
}
