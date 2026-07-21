<?php

namespace App\Domain\Auth\Support;

use Illuminate\Support\Str;

final class ConvoLabAdminEmails
{
    public static function contains(string $email): bool
    {
        $configured = config('services.convolab.admin_emails', []);
        if (! is_array($configured)) {
            return false;
        }

        $email = Str::lower(trim($email));

        return collect($configured)
            ->filter(static fn (mixed $candidate): bool => is_string($candidate))
            ->map(static fn (string $candidate): string => Str::lower(trim($candidate)))
            ->contains($email);
    }
}
