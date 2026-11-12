<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\ProcessJournalAnalysis;
use App\Domain\Journal\Actions\StartJournalAnalysis;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Services\GenerateJournalFeedback;
use App\Jobs\AnalyzeJournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\Journal\JournalFeedbackFixture;

class ProcessJournalAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_saves_feedback_and_only_generates_a_title_for_an_untitled_entry(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ますた。']);
        $analysis = app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1);
        $result = JournalFeedbackFixture::result();
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->with($entry->body)->andReturn($result);
        $process = app(ProcessJournalAnalysis::class);
        $job = new AnalyzeJournalEntry($analysis->id);
        $job->handle($process);
        (new AnalyzeJournalEntry($analysis->id))->handle($process);
        $job->failed(new RuntimeException('late failure'));
        $this->assertSame(JournalAnalysisStatus::Ready, $analysis->fresh()->status);
        $this->assertSame($result, $analysis->fresh()->result);
        $this->assertSame('A movie night', $entry->fresh()->title);
        $this->assertSame(2, $entry->fresh()->version);
        $this->assertSame('映画を見ますた。', $entry->fresh()->body);
    }

    public function test_user_title_is_never_replaced(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ますた。', 'title' => 'My own title']);
        $analysis = app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1);
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->andReturn(JournalFeedbackFixture::result());
        (new AnalyzeJournalEntry($analysis->id))->handle(app(ProcessJournalAnalysis::class));
        $this->assertSame('My own title', $entry->fresh()->title);
        $this->assertSame(1, $entry->fresh()->version);
    }

    public function test_fresh_lease_is_skipped_but_a_stale_worker_can_be_replaced(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ますた。']);
        $analysis = app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1);
        $analysis->status = JournalAnalysisStatus::Processing;
        $analysis->attempt_id = strtolower((string) Str::ulid());
        $analysis->processing_started_at = now();
        $analysis->save();
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->andReturn(JournalFeedbackFixture::result());
        (new AnalyzeJournalEntry($analysis->id))->handle(app(ProcessJournalAnalysis::class));
        $this->assertSame(JournalAnalysisStatus::Processing, $analysis->fresh()->status);
        $this->travel(121)->seconds();
        (new AnalyzeJournalEntry($analysis->id))->handle(app(ProcessJournalAnalysis::class));
        $this->assertSame(JournalAnalysisStatus::Ready, $analysis->fresh()->status);
    }

    public function test_edit_during_generation_keeps_the_new_writing_and_does_not_apply_an_old_title(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ますた。']);
        $analysis = app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1);
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($entry) {
            $entry->body = '新しい文章。';
            $entry->version = 2;
            $entry->save();

            return JournalFeedbackFixture::result();
        });
        (new AnalyzeJournalEntry($analysis->id))->handle(app(ProcessJournalAnalysis::class));
        $this->assertSame('新しい文章。', $entry->fresh()->body);
        $this->assertNull($entry->fresh()->title);
        $this->assertSame(2, $entry->fresh()->version);
    }

    public function test_obsolete_attempt_cannot_commit_or_fail_a_newer_attempt(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ますた。']);
        $analysis = app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1);
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($analysis) {
            $fresh = $analysis->fresh();
            $fresh->attempt_id = strtolower((string) Str::ulid());
            $fresh->save();

            return JournalFeedbackFixture::result();
        });
        $job = new AnalyzeJournalEntry($analysis->id);
        $job->handle(app(ProcessJournalAnalysis::class));
        $job->failed(new RuntimeException('old worker timeout'));
        $this->assertSame(JournalAnalysisStatus::Processing, $analysis->fresh()->status);
        $this->assertNull($analysis->fresh()->result);
        $this->assertNull($entry->fresh()->title);
    }

    public function test_deleted_entries_skip_the_provider_and_provider_failures_leave_a_retryable_error(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ますた。']);
        $analysis = app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1);
        $entry->delete();
        $provider = $this->mock(GenerateJournalFeedback::class);
        (new AnalyzeJournalEntry($analysis->id))->handle(app(ProcessJournalAnalysis::class));
        $provider->shouldNotHaveReceived('handle');
        $entry->restore();
        $provider->shouldReceive('handle')->once()->andThrow(new RuntimeException('private provider detail'));
        try {
            (new AnalyzeJournalEntry($analysis->id))->handle(app(ProcessJournalAnalysis::class));
            $this->fail('The queue must record a failed attempt.');
        } catch (RuntimeException) {
            $this->assertSame(JournalAnalysisStatus::Failed, $analysis->fresh()->status);
            $this->assertSame('Feedback could not be completed. Please try again.', $analysis->fresh()->error_message);
            $this->assertNull($analysis->fresh()->result);
        }
    }
}
