<?php

namespace App\Domain\Auth\Actions;

use App\Jobs\SendPasswordResetLink;
use Illuminate\Support\Str;

class SendPasswordResetLinkAction
{
    public function handle(string $email): void
    {
        // Keep direct callers on the same normalization boundary as the FormRequest.
        SendPasswordResetLink::dispatch(Str::lower(trim($email)));
    }
}
