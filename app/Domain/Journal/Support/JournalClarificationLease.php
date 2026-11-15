<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Enums\JournalClarificationStatus as Status;
use App\Domain\Journal\Models\JournalClarification;

final class JournalClarificationLease
{
    public const SECONDS = 120;

    public static function expired(JournalClarification $state): bool
    {
        if (! in_array($state->status, [Status::Queued, Status::Processing], true)) {
            return false;
        }
        $since = $state->status === Status::Processing ? $state->processing_started_at : $state->updated_at;

        return $since === null || $since->lte(now()->subSeconds(self::SECONDS));
    }
}
