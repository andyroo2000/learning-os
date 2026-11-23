<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\ConvoLabBetaRequest;
use App\Mail\ConvoLabBetaInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class SendBetaInvitationAction
{
    public function handle(ConvoLabBetaRequest $request): ConvoLabBetaRequest
    {
        // Serialize sends for this request. Never auto-retry a transaction containing mail.
        // A process failure after transport acceptance can repeat email, but always with the same code.
        return DB::transaction(function () use ($request): ConvoLabBetaRequest {
            $locked = ConvoLabBetaRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->invitation_sent_at !== null) {
                return $locked->load('inviteCode');
            }
            $invite = $locked->inviteCode()->lockForUpdate()->first();
            $locked->setRelation('inviteCode', $invite);
            abort_if($invite === null || $invite->used_at !== null || $invite->used_by !== null || $invite->convolab_used_by !== null, 409, 'This invitation is no longer available. Check Invite Codes.');
            try {
                Mail::to($locked->email)->send(new ConvoLabBetaInvitationMail($invite->code));
            } catch (\Throwable $e) {
                report($e);
                abort(503, 'The request is approved, but the invitation could not be sent. Retry to send the same code.');
            }
            $locked->invitation_sent_at = now();
            $locked->save();

            return $locked;
        });
    }
}
