<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Admin\Models\ConvoLabBetaRequest;
use App\Mail\ConvoLabBetaAccessRequestMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class SendConvoLabBetaAccessRequestAction
{
    /** @param list<string> $devices Validated by RequestConvoLabBetaAccessRequest. */
    public function execute(string $email, array $devices, string $message): void
    {
        $record = ConvoLabBetaRequest::query()->firstOrCreate(
            ['email' => Str::lower(trim($email))],
            ['devices' => $devices, 'message' => $message],
        );
        if (! $record->wasRecentlyCreated) {
            return;
        }
        // The admin inbox is authoritative. Notification failure must not lose a saved request.
        try {
            $recipient = config('services.convolab.beta_request_email');
            if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Beta request notification recipient is not configured.');
            }
            Mail::to($recipient)->send(new ConvoLabBetaAccessRequestMail($record->email, $devices, $message));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
