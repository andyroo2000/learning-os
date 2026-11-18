<?php

namespace App\Domain\Admin\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class JournalAnalyticsReader
{
    public function events(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return $this->cohort('journal_submission_events', 's')
            ->where('s.submitted_at', '>=', $start)->where('s.submitted_at', '<=', $end)
            ->select('s.*');
    }

    public function costs(CarbonImmutable $start, CarbonImmutable $end): iterable
    {
        $groups = ['s.user_id', 's.operation', 's.provider', 's.model'];

        return $this->cohort('ai_usage_events', 's')->where('s.operation', 'like', 'journal.%')
            ->where('s.created_at', '>=', $start)->where('s.created_at', '<=', $end)
            ->select($groups)->selectRaw('COUNT(*) AS requests, COUNT(*) - COUNT(estimated_micro_usd) AS unknown_requests')
            ->selectRaw('COALESCE(SUM(estimated_micro_usd), 0) AS micro_usd')
            ->groupBy($groups)->orderBy('s.user_id')->get();
    }

    public function cohortFollowups(JournalAnalyticsRange $range): int
    {
        $roots = $this->events($range->start, $range->end)->where('s.kind', 'original')->select('s.root_entry_id');

        return DB::table('journal_submission_events')->where('kind', 'followup')
            ->whereIn('root_entry_id', $roots)->where('submitted_at', '<=', $range->end)->count();
    }

    public function trackingSince(): ?string
    {
        return DB::table('ai_usage_events')->where('operation', 'like', 'journal.%')->min('created_at');
    }

    private function cohort(string $table, string $alias): Builder
    {
        return DB::table($table.' as '.$alias)
            ->join('admin_user_projections as p', 'p.user_id', '=', $alias.'.user_id')->where('p.role', '!=', 'admin');
    }
}
