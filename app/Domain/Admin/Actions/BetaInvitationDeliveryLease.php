<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\ConvoLabBetaRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BetaInvitationDeliveryLease
{
    public function claim(ConvoLabBetaRequest $request): ConvoLabBetaRequest
    {
        return DB::transaction(function () use ($request): ConvoLabBetaRequest {
            $locked = ConvoLabBetaRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->invitation_sent_at !== null) {
                return $locked->load('inviteCode');
            }
            abort_if($locked->sending_started_at !== null && $locked->sending_started_at->greaterThan(now()->subMinutes(5)), 409, 'An invitation is already being sent. Refresh shortly.');
            $invite = $locked->inviteCode()->lockForUpdate()->first();
            abort_if($invite === null || $invite->used_at !== null || $invite->used_by !== null || $invite->convolab_used_by !== null, 409, 'This invitation is no longer available. Check Invite Codes.');
            $locked->setRelation('inviteCode', $invite);
            $locked->sending_token = (string) Str::uuid();
            $locked->sending_started_at = now();
            $locked->save();

            return $locked;
        });
    }

    public function finish(ConvoLabBetaRequest $request, bool $sent): void
    {
        $changes = ['sending_token' => null, 'sending_started_at' => null];
        if ($sent) {
            $changes['invitation_sent_at'] = now();
        }
        // A slow, expired sender cannot clear a newer sender's lease.
        ConvoLabBetaRequest::query()->whereKey($request->id)
            ->where('sending_token', $request->sending_token)
            ->whereNull('invitation_sent_at')->update($changes);
    }
}
