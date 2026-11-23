<?php

namespace App\Domain\Auth\Actions;

use App\Mail\ConvoLabBetaAccessRequestMail;
use Illuminate\Support\Facades\Mail;

final class SendConvoLabBetaAccessRequestAction
{
    /** @param list<string> $devices */
    public function execute(string $email, array $devices, string $message): void
    {
        // Send before reporting success, so a transport failure leaves the form retryable.
        Mail::to(config('services.convolab.beta_request_email'))
            ->send(new ConvoLabBetaAccessRequestMail($email, $devices, $message));
    }
}
