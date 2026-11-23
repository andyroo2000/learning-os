<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\ConvoLabBetaRequest;
use App\Mail\ConvoLabBetaInvitationMail;
use Illuminate\Support\Facades\Mail;

final class SendBetaInvitationAction
{
    public function __construct(private readonly BetaInvitationDeliveryLease $lease) {}

    public function handle(ConvoLabBetaRequest $request): ConvoLabBetaRequest
    {
        $claimed = $this->lease->claim($request);
        if ($claimed->invitation_sent_at !== null) {
            return $claimed;
        }
        // Delivery happens without a database lock. A crash/expired lease may repeat
        // the email on retry, but the reserved single-use code stays the same.
        try {
            Mail::to($claimed->email)->send(new ConvoLabBetaInvitationMail($claimed->inviteCode->code));
        } catch (\Throwable $e) {
            $this->lease->finish($claimed, false);
            report($e);
            abort(503, 'The request is approved, but the invitation could not be sent. Retry to send the same code.');
        }
        $this->lease->finish($claimed, true);

        return $claimed->fresh('inviteCode');
    }
}
