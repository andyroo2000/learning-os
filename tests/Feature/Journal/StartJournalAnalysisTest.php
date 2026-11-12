<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\StartJournalAnalysis;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StartJournalAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_snapshots_writing_and_retry_reuses_the_same_record(): void
    {
        $entry = JournalEntry::factory()->create(['body' => " 映画を見た。\n"]);
        $action = app(StartJournalAnalysis::class);
        $first = $action->handle($entry->user_id, strtoupper($entry->id), 1);
        $this->travel(1)->minute();
        $retry = $action->handle($entry->user_id, $entry->id, 1);
        $this->assertSame($first->id, $retry->id);
        $this->assertTrue($first->updated_at->equalTo($retry->updated_at));
        $this->assertSame($entry->body, $retry->source_body);
        $this->assertSame(JournalAnalysisStatus::Queued, $retry->status);
        $this->assertNull($entry->fresh()->title);
        $this->assertDatabaseCount('journal_analyses', 1);
    }

    public function test_failed_submission_can_be_retried_without_losing_its_source(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見た。']);
        $action = app(StartJournalAnalysis::class);
        $analysis = $action->handle($entry->user_id, $entry->id, 1);
        $analysis->status = JournalAnalysisStatus::Failed;
        $analysis->error_message = 'Provider unavailable';
        $analysis->result = ['partial' => 'discard'];
        $analysis->save();
        $retry = $action->handle($entry->user_id, $entry->id, 1);
        $this->assertSame($analysis->id, $retry->id);
        $this->assertSame(JournalAnalysisStatus::Queued, $retry->status);
        $this->assertNull($retry->error_message);
        $this->assertNull($retry->result);
        $this->assertSame($entry->body, $retry->source_body);
    }

    public function test_stale_submission_does_not_queue_analysis_for_unseen_edits(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '新しい文章。', 'version' => 2]);
        $this->expectException(JournalVersionConflict::class);
        app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1);
    }

    public function test_whitespace_only_entry_cannot_be_submitted(): void
    {
        $entry = JournalEntry::factory()->create(['body' => "　 \n"]);
        $this->expectException(ValidationException::class);
        app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1);
    }

    public function test_another_user_cannot_submit_a_private_entry(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '秘密の文章。']);
        $this->expectException(ModelNotFoundException::class);
        app(StartJournalAnalysis::class)->handle(User::factory()->create()->id, $entry->id, 1);
    }

    public function test_deleted_entry_cannot_be_submitted(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '削除した文章。']);
        $entry->delete();
        $this->expectException(ModelNotFoundException::class);
        app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1);
    }
}
