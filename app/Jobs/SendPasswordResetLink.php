<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;

final class SendPasswordResetLink implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [65, 130];

    public readonly string $email;

    public function __construct(string $email)
    {
        $this->email = Str::lower(trim($email));
    }

    public function uniqueId(): string
    {
        return hash('sha256', $this->email);
    }

    public function handle(): void
    {
        $status = Password::sendResetLink(['email' => $this->email]);

        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return;
        }

        throw new RuntimeException("Unexpected password broker status [{$status}].");
    }
}
