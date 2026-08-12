<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Models\StudyActivitySession;
use App\Domain\Study\Support\StudyActivitySessionId;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeleteStudyActivitySessionAction
{
    /**
     * Delete an owned editable session.
     *
     * Missing and unowned IDs are retry-safe successes. Automatic sessions are
     * immutable and return false so the HTTP adapter can report that conflict.
     */
    public function handle(int $userId, string $clientSessionId): bool
    {
        $clientSessionId = StudyActivitySessionId::normalize($clientSessionId);

        if (! Str::isUlid($clientSessionId) && ! Str::isUuid($clientSessionId)) {
            return true;
        }

        return DB::transaction(function () use ($userId, $clientSessionId): bool {
            // Match activity upserts: the owner row exists even when the session
            // does not yet, so it serializes delete against an in-flight first write.
            User::query()
                ->whereKey($userId)
                ->lockForUpdate()
                ->firstOrFail();

            $session = StudyActivitySession::query()
                ->where('user_id', $userId)
                ->where('client_session_id', $clientSessionId)
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                return true;
            }

            if ($session->source === StudyActivitySource::Automatic) {
                return false;
            }

            $session->delete();

            return true;
        });
    }
}
