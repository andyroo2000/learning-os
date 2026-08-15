<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Data\GoogleCalendarConnectIntentClaim;
use App\Domain\Calendar\Models\GoogleCalendarConnectIntent;
use Illuminate\Support\Facades\DB;

final class ClaimGoogleCalendarConnectIntentAction
{
    public function handle(mixed $state): ?GoogleCalendarConnectIntentClaim
    {
        if (! is_string($state) || preg_match('/\A[0-9a-f]{64}\z/D', $state) !== 1) {
            return null;
        }

        return DB::transaction(function () use ($state): ?GoogleCalendarConnectIntentClaim {
            $intent = GoogleCalendarConnectIntent::query()
                ->whereKey(hash('sha256', $state))
                ->lockForUpdate()
                ->first();

            if ($intent === null) {
                return null;
            }

            $intent->delete();
            if ($intent->expires_at->lessThanOrEqualTo(now())) {
                return null;
            }

            return new GoogleCalendarConnectIntentClaim(
                (int) $intent->user_id,
                (string) $intent->completion_target,
            );
        }, 3);
    }
}
