<?php

namespace App\Domain\Journal\Actions;

use App\Domain\Journal\Enums\JournalClarificationStatus as Status;
use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Support\JournalClarificationWrite;
use App\Jobs\ClarifyJournalEntry;
use Illuminate\Support\Facades\Bus;
use Throwable;

final class DispatchJournalClarification
{
    /** Run after the reservation transaction commits; failed queue writes leave a retryable state. */
    public function handle(JournalClarification $state): JournalClarification
    {
        if (! in_array($state->status, [Status::Queued, Status::Processing], true)) {
            return $state;
        }
        try {
            // Database revision/lease guards, not a cache uniqueness TTL, own duplicate safety.
            Bus::dispatch(new ClarifyJournalEntry($state->id, $state->revision));
        } catch (Throwable $exception) {
            report($exception);
            JournalClarificationWrite::run($state->id, function (JournalClarification $locked) use ($state): void {
                if ($locked->revision === $state->revision && $locked->status === Status::Queued) {
                    $locked->forceFill(['status' => Status::Failed, 'error_message' => 'The meaning check could not start. Please try again.'])->save();
                }
            });
        }

        return $state->fresh() ?? $state;
    }
}
