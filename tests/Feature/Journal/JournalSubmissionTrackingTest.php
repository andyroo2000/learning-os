<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\StartJournalAnalysis;
use App\Domain\Journal\Actions\StartJournalClarification;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalReply;
use App\Domain\Journal\Support\BackfillJournalSubmissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JournalSubmissionTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_drafts_are_not_counted_and_submitting_through_both_stages_counts_once(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見た。']);
        $this->assertDatabaseCount('journal_submission_events', 0);
        $clarification = app(StartJournalClarification::class)->handle($entry->user_id, $entry->id, 1);
        $clarification->forceFill(['status' => 'resolved'])->save();
        app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1);
        app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1);
        $this->assertDatabaseCount('journal_submission_events', 1);
        $this->assertDatabaseHas('journal_submission_events', ['kind' => 'original', 'characters' => 6]);
    }

    public function test_backfill_uses_first_submission_and_retains_deleted_entries(): void
    {
        $entry = JournalEntry::factory()->create();
        JournalClarification::factory()->create(['entry_id' => $entry->id, 'source_body' => '最初。', 'created_at' => '2026-11-01 12:00:00']);
        $analysis = JournalAnalysis::factory()->create(['entry_id' => $entry->id, 'source_body' => '後の文章。', 'created_at' => '2026-11-02 12:00:00']);
        JournalReply::factory()->create(['analysis_id' => $analysis->id, 'answer' => '返事。']);
        $entry->delete();
        app(BackfillJournalSubmissions::class)->handle();
        app(BackfillJournalSubmissions::class)->handle();
        $this->assertDatabaseCount('journal_submission_events', 2);
        $this->assertDatabaseHas('journal_submission_events', ['source_key' => 'entry:'.$entry->id, 'characters' => 3, 'submitted_at' => '2026-11-01 12:00:00.000000']);
        $this->assertSame(1, DB::table('journal_submission_events')->where('kind', 'followup')->count());
    }
}
