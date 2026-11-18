<?php

namespace App\Domain\Journal\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class BackfillJournalSubmissions
{
    public function handle(): void
    {
        $sources = $this->entries('journal_clarifications')->unionAll($this->entries('journal_analyses'));
        foreach (DB::query()->fromSub($sources, 'submitted')->orderBy('submitted_at')->orderBy('id')->cursor() as $row) {
            JournalSubmission::record(['source_key' => 'entry:'.$row->id, 'user_id' => $row->user_id,
                'root_entry_id' => $row->root_entry_id ?? $row->id,
                'kind' => $row->root_entry_id === null ? 'original' : 'followup',
                'characters' => mb_strlen($row->source_body), 'submitted_at' => $row->submitted_at]);
        }
        $replies = DB::table('journal_replies as r')->join('journal_analyses as a', 'a.id', '=', 'r.analysis_id')
            ->join('journal_entries as e', 'e.id', '=', 'a.entry_id')
            ->select('r.id', 'r.answer', 'r.created_at', 'e.user_id', 'e.root_entry_id', 'e.id as entry_id');
        foreach ($replies->cursor() as $row) {
            JournalSubmission::record(['source_key' => 'reply:'.$row->id, 'user_id' => $row->user_id,
                'root_entry_id' => $row->root_entry_id ?? $row->entry_id, 'kind' => 'followup',
                'characters' => mb_strlen($row->answer), 'submitted_at' => $row->created_at]);
        }
    }

    private function entries(string $table): Builder
    {
        return DB::table($table.' as s')->join('journal_entries as e', 'e.id', '=', 's.entry_id')
            ->select('e.id', 'e.user_id', 'e.root_entry_id', 's.source_body', 's.created_at as submitted_at');
    }
}
