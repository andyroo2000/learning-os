<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_submission_events', function (Blueprint $table): void {
            $table->string('source_key', 40)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Keep aggregate history after deleting an entry, but not after deleting its owner.
            $table->ulid('root_entry_id');
            $table->string('kind', 12);
            $table->unsignedInteger('characters');
            $table->timestamp('submitted_at', 6);
            $table->index(['submitted_at', 'user_id']);
            $table->index(['root_entry_id', 'kind']);
        });
        $this->backfill();
    }

    private function backfill(): void
    {
        $sources = $this->entries('journal_clarifications')->unionAll($this->entries('journal_analyses'));
        foreach (DB::query()->fromSub($sources, 'submitted')->orderBy('submitted_at')->orderBy('id')->cursor() as $row) {
            DB::table('journal_submission_events')->insertOrIgnore(['source_key' => 'entry:'.$row->id, 'user_id' => $row->user_id,
                'root_entry_id' => $row->root_entry_id ?? $row->id,
                'kind' => $row->root_entry_id === null ? 'original' : 'followup',
                'characters' => mb_strlen($row->source_body), 'submitted_at' => $row->submitted_at]);
        }
        $replies = DB::table('journal_replies as r')->join('journal_analyses as a', 'a.id', '=', 'r.analysis_id')
            ->join('journal_entries as e', 'e.id', '=', 'a.entry_id')
            ->select('r.id', 'r.answer', 'r.created_at', 'e.user_id', 'e.root_entry_id', 'e.id as entry_id');
        foreach ($replies->cursor() as $row) {
            DB::table('journal_submission_events')->insertOrIgnore(['source_key' => 'reply:'.$row->id, 'user_id' => $row->user_id,
                'root_entry_id' => $row->root_entry_id ?? $row->entry_id, 'kind' => 'followup',
                'characters' => mb_strlen($row->answer), 'submitted_at' => $row->created_at]);
        }
    }

    private function entries(string $table): Builder
    {
        return DB::table($table.' as s')->join('journal_entries as e', 'e.id', '=', 's.entry_id')
            ->select('e.id', 'e.user_id', 'e.root_entry_id', 's.source_body', 's.created_at as submitted_at');
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_submission_events');
    }
};
