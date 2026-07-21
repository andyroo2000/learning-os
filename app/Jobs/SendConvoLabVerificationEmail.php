<?php

namespace App\Jobs;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Auth\Actions\IssueConvoLabVerificationTokenAction;
use App\Mail\ConvoLabVerificationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

final class SendConvoLabVerificationEmail implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [2, 4, 8];

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(IssueConvoLabVerificationTokenAction $issueToken): void
    {
        $account = AdminUserProjection::query()->where('user_id', $this->userId)->first();
        if (! $account instanceof AdminUserProjection || $account->email_verified) {
            return;
        }

        $token = $issueToken->handle($this->userId);
        if ($token === null) {
            return;
        }

        Mail::to($account->email)->send(new ConvoLabVerificationMail(
            name: $account->name,
            verificationUrl: rtrim((string) config('services.convolab.client_url'), '/')
                .'/verify-email/'.$token,
        ));
    }
}
