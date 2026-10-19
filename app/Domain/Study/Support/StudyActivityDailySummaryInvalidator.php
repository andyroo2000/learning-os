<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Models\StudyActivitySession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Ledger writes and summary fills must hold the same owner-row lock. */
final class StudyActivityDailySummaryInvalidator
{
    /** @param Collection<int, StudyActivitySession> $before
     * @param  Collection<array-key, StudyActivitySession>  $after
     */
    public function changed(int $userId, Collection $before, Collection $after): void
    {
        $previous = $before->keyBy('client_session_id');
        $footprints = [];
        foreach ($after->keyBy('client_session_id') as $clientId => $session) {
            $old = isset($previous[$clientId])
                ? StudyActivitySummaryFootprint::fromSession($previous[$clientId])
                : null;
            $new = StudyActivitySummaryFootprint::fromSession($session);
            if ($new->matches($old)) {
                continue;
            }
            $footprints[] = $new;
            if ($old !== null) {
                $footprints[] = $old;
            }
        }
        $this->forgetFootprints($userId, $footprints);
    }

    /** @param Collection<array-key, StudyActivitySession> $sessions */
    public function removed(int $userId, Collection $sessions): void
    {
        $this->forgetFootprints($userId, $sessions->map(
            fn (StudyActivitySession $session): StudyActivitySummaryFootprint => StudyActivitySummaryFootprint::fromSession($session),
        )->all());
    }

    /** @param list<StudyActivitySummaryFootprint> $footprints */
    private function forgetFootprints(int $userId, array $footprints): void
    {
        // Delete only already-materialized days. A long session never expands
        // into an unbounded list of dates or timezones on the write path.
        foreach (array_chunk($footprints, 100) as $chunk) {
            DB::table('study_activity_daily_summaries')
                ->where('user_id', $userId)
                ->where(function ($query) use ($chunk): void {
                    foreach ($chunk as $footprint) {
                        $query->orWhere(function ($overlap) use ($footprint): void {
                            $overlap->where('started_at', '<', $footprint->endsAt->format('Y-m-d H:i:s.u'))
                                ->where('ended_at', '>', $footprint->startsAt->format('Y-m-d H:i:s.u'));
                        });
                    }
                })->delete();
        }
    }
}
