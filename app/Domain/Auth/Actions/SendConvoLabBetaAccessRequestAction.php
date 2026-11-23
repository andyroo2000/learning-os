<?php

namespace App\Domain\Auth\Actions;

use App\Mail\ConvoLabBetaAccessRequestMail;
use Illuminate\Support\Facades\Mail;

final class SendConvoLabBetaAccessRequestAction
{
    /** @param list<string> $devices */
    public function execute(string $email, array $devices, string $message): void
    {
        $recipient = config('services.convolab.beta_request_email');
        abort_unless(is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL), 503, 'Beta requests are temporarily unavailable.');

        // Send before reporting success, so a transport failure leaves the form retryable.
        Mail::to($recipient)
            ->send(new ConvoLabBetaAccessRequestMail($email, $devices, $message));
    }
}
