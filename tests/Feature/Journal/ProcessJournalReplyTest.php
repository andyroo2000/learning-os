<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\ProcessJournalReply;
use App\Domain\Journal\Actions\StartJournalReply;
use App\Domain\Journal\Data\JournalReplyInput;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Services\GenerateJournalFeedback;
use App\Jobs\AnalyzeJournalReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\Journal\JournalFeedbackFixture;

class ProcessJournalReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_appends_a_corrected_answer_without_changing_original_writing_or_title(): void
    {
        [$entry, $analysis, $reply] = $this->pending();
        $result = JournalFeedbackFixture::result();
        $result['correctedText'] = '友達と見ました。';
        $result['correctedReading'] = '友達[ともだち]と見[み]ました。';
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()
            ->with($reply->answer, ['diary' => '映画を見ました。', 'reading' => '映画[えいが]を見[み]ました。', 'question' => $analysis->result['question']])->andReturn($result);
        $job = new AnalyzeJournalReply($reply->id);
        $job->handle(app(ProcessJournalReply::class));
        $job->failed(new RuntimeException('late failure'));
        $this->assertSame(JournalAnalysisStatus::Ready, $reply->fresh()->status);
        $this->assertSame("映画を見ました。\n\n友達と見ました。", $reply->fresh()->result['entryText']);
        $this->assertSame($reply->id, $analysis->fresh()->current_reply_id);
        $this->assertNull($analysis->fresh()->pending_reply_id);
        $this->assertSame(1, $analysis->fresh()->reply_revision);
        $this->assertSame($entry->body, $entry->fresh()->body);
        $this->assertSame($entry->title, $entry->fresh()->title);
        $this->assertGreaterThan($job->timeout, (int) config('queue.connections.database.retry_after'));
        $this->assertTrue($job->afterCommit);
    }

    public function test_failure_frees_the_pending_slot_and_the_same_request_can_retry(): void
    {
        [$entry, $analysis, $reply, $input] = $this->pending();
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('provider details'));
        try {
            (new AnalyzeJournalReply($reply->id))->handle(app(ProcessJournalReply::class));
            $this->fail('Expected provider failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('provider details', $exception->getMessage());
        }
        $this->assertSame(JournalAnalysisStatus::Failed, $reply->fresh()->status);
        $this->assertNull($analysis->fresh()->pending_reply_id);
        $this->assertStringNotContainsString('provider details', $reply->fresh()->error_message);
        app(StartJournalReply::class)->handle($entry->user_id, $input);
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->andReturn(JournalFeedbackFixture::result());
        (new AnalyzeJournalReply($reply->id))->handle(app(ProcessJournalReply::class));
        $this->assertSame(JournalAnalysisStatus::Ready, $reply->fresh()->status);
    }

    public function test_edits_during_generation_cannot_apply_old_answers(): void
    {
        [$entry, $analysis, $reply] = $this->pending();
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($entry) {
            $entry->body = '別の日。';
            $entry->save();

            return JournalFeedbackFixture::result();
        });
        (new AnalyzeJournalReply($reply->id))->handle(app(ProcessJournalReply::class));
        $this->assertSame(JournalAnalysisStatus::Failed, $reply->fresh()->status);
        $this->assertNull($analysis->fresh()->current_reply_id);
        $this->assertNull($analysis->fresh()->pending_reply_id);
    }

    public function test_a_replaced_attempt_cannot_finish_or_fail_the_new_worker(): void
    {
        [, $analysis, $reply] = $this->pending();
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($reply) {
            $reply->refresh();
            $reply->attempt_id = strtolower((string) Str::ulid());
            $reply->save();

            return JournalFeedbackFixture::result();
        });
        $job = new AnalyzeJournalReply($reply->id);
        $job->handle(app(ProcessJournalReply::class));
        $job->failed(new RuntimeException('old timeout'));
        $this->assertSame(JournalAnalysisStatus::Processing, $reply->fresh()->status);
        $this->assertNull($reply->fresh()->result);
        $this->assertSame($reply->id, $analysis->fresh()->pending_reply_id);
    }

    public function test_deleted_entries_do_not_repeat_provider_calls(): void
    {
        [$entry, , $reply] = $this->pending();
        $entry->delete();
        $this->mock(GenerateJournalFeedback::class)->shouldNotReceive('handle');
        (new AnalyzeJournalReply($reply->id))->handle(app(ProcessJournalReply::class));
    }

    public function test_same_serialized_job_cannot_reclaim_but_a_new_retry_can(): void
    {
        [, , $reply] = $this->pending();
        $old = new AnalyzeJournalReply($reply->id);
        $reply->status = JournalAnalysisStatus::Processing;
        $reply->attempt_id = $old->attempt->token;
        $reply->processing_started_at = now();
        $reply->save();
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->andReturn(JournalFeedbackFixture::result());
        $process = app(ProcessJournalReply::class);
        (new AnalyzeJournalReply($reply->id))->handle($process);
        $this->travel(121)->seconds();
        $old->handle($process);
        $this->assertSame(JournalAnalysisStatus::Processing, $reply->fresh()->status);
        (new AnalyzeJournalReply($reply->id))->handle($process);
        (new AnalyzeJournalReply($reply->id))->handle($process);
        $this->assertSame(JournalAnalysisStatus::Ready, $reply->fresh()->status);
    }

    private function pending(): array
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ますた。', 'title' => 'My title']);
        $analysis = JournalAnalysis::factory()->create(['entry_id' => $entry->id, 'source_body' => $entry->body,
            'status' => JournalAnalysisStatus::Ready, 'result' => JournalFeedbackFixture::result()]);
        $input = new JournalReplyInput(['id' => (string) Str::ulid(), 'analysisId' => $analysis->id,
            'answer' => '友達と見ますた。', 'expectedRevision' => 0, 'replace' => false]);
        $reply = app(StartJournalReply::class)->handle($entry->user_id, $input);

        return [$entry, $analysis, $reply, $input];
    }
}
