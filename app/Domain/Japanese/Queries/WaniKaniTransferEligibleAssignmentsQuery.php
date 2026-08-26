<?php

namespace App\Domain\Japanese\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class WaniKaniTransferEligibleAssignmentsQuery
{
    public function forUser(int $userId): Builder
    {
        return DB::table('user_wanikani_assignments as assignments')
            ->join('wanikani_subjects as subjects', 'subjects.subject_id', '=', 'assignments.subject_id')
            ->leftJoin('study_vocab_variant_groups as groups', function ($join) use ($userId): void {
                $join->on('groups.wanikani_subject_id', '=', 'assignments.subject_id')
                    ->where('groups.user_id', '=', $userId);
            })
            ->where('assignments.user_id', $userId)
            ->whereNotNull('assignments.passed_at')
            ->where('assignments.hidden', false)
            ->whereNull('subjects.hidden_at')
            ->whereIn('subjects.subject_type', ['vocabulary', 'kana_vocabulary'])
            ->whereNull('groups.id');
    }
}
