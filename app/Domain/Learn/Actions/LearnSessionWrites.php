<?php

namespace App\Domain\Learn\Actions;

use App\Domain\Learn\Models\LearnSession;

final class LearnSessionWrites
{
    public function claim(LearnSession $session, array $input): void
    {
        abort_unless($session->version === $input['expectedVersion'], 409, 'This lesson changed. Reload it before trying again.');
        $claimed = LearnSession::whereKey($session->id)->where('version', $input['expectedVersion'])
            ->where(fn ($query) => $query->whereNull('pending_request_id')->orWhere('pending_at', '<', now()->subMinutes(3)))
            ->update(['pending_request_id' => $input['requestId'], 'pending_at' => now()]);
        abort_unless($claimed === 1, 409, 'This lesson changed or the tutor is still responding. Reload the lesson before trying again.');
    }

    public function complete(LearnSession $session, array $input, array $state): LearnSession
    {
        $saved = LearnSession::whereKey($session->id)->where('version', $input['expectedVersion'])
            ->where('pending_request_id', $input['requestId'])->update([
                'state' => json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'version' => $input['expectedVersion'] + 1, 'last_request_id' => $input['requestId'],
                'pending_request_id' => null, 'pending_at' => null,
            ]);
        abort_unless($saved === 1, 409, 'This response was superseded. Reload the lesson to see the latest state.');

        return $session->fresh();
    }

    public function release(LearnSession $session, string $requestId): void
    {
        LearnSession::whereKey($session->id)->where('pending_request_id', $requestId)
            ->update(['pending_request_id' => null, 'pending_at' => null]);
    }
}
