<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\ProcessJournalClarification;
use App\Domain\Journal\Enums\JournalClarificationStatus as Status;
use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Services\CheckJournalMeaning;
use App\Jobs\ClarifyJournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ProcessJournalClarificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_question_is_saved_once_and_a_duplicate_job_does_not_skip_it(): void
    {
        $state = $this->state();
        $question = ['prompt' => 'Who was driving?', 'choices' => ['I was driving', 'Molly was driving']];
        $this->mock(CheckJournalMeaning::class)->shouldReceive('handle')->once()->with($state->source_body, [])->andReturn([
            'resolved' => false, 'question' => $question, 'confirmedMeaning' => '',
        ]);
        $job = new ClarifyJournalEntry(strtoupper($state->id), 0);
        $this->assertTrue($job->afterCommit);
        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
        $job->handle(app(ProcessJournalClarification::class));
        (new ClarifyJournalEntry($state->id, 0))->handle(app(ProcessJournalClarification::class));
        $fresh = $state->fresh();
        $this->assertSame(Status::Waiting, $fresh->status);
        $this->assertTrue(Str::isUlid($fresh->question['id']));
        $this->assertSame($question['prompt'], $fresh->question['prompt']);
        $this->assertDatabaseCount('journal_analyses', 0);
    }

    public function test_answers_are_rechecked_and_resolution_does_not_itself_submit_feedback(): void
    {
        $state = $this->state();
        $answers = [['question' => 'Who was driving?', 'answer' => 'Me', 'questionId' => strtolower((string) Str::ulid())]];
        $state->forceFill(['answers' => $answers, 'revision' => 1])->save();
        $this->mock(CheckJournalMeaning::class)->shouldReceive('handle')->once()
            ->with($state->source_body, [['question' => 'Who was driving?', 'answer' => 'Me']])
            ->andReturn(['resolved' => true, 'question' => null, 'confirmedMeaning' => 'The writer drove.']);
        $job = new ClarifyJournalEntry($state->id, 1);
        $job->handle(app(ProcessJournalClarification::class));
        $resolvedAt = $state->fresh()->resolved_at->toJSON();
        $job->failed(new RuntimeException('late failure'));
        $this->assertSame(Status::Resolved, $state->fresh()->status);
        $this->assertSame('The writer drove.', $state->fresh()->confirmed_meaning);
        $this->assertSame($resolvedAt, $state->fresh()->resolved_at->toJSON());
        $this->assertDatabaseCount('journal_analyses', 0);
    }

    public function test_obsolete_revision_and_fresh_lease_skip_provider_calls(): void
    {
        $state = $this->state();
        $state->forceFill(['revision' => 1, 'status' => Status::Processing, 'processing_started_at' => now()])->save();
        $this->mock(CheckJournalMeaning::class)->shouldNotReceive('handle');
        (new ClarifyJournalEntry($state->id, 0))->handle(app(ProcessJournalClarification::class));
        (new ClarifyJournalEntry($state->id, 1))->handle(app(ProcessJournalClarification::class));
        $this->assertSame(Status::Processing, $state->fresh()->status);
    }

    public function test_stale_lease_is_reclaimed_and_old_worker_cannot_finish_or_fail_new_attempt(): void
    {
        $state = $this->state();
        $state->forceFill(['status' => Status::Processing, 'processing_started_at' => now()->subSeconds(121)])->save();
        $this->mock(CheckJournalMeaning::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($state) {
            $state->fresh()->forceFill(['attempt_id' => strtolower((string) Str::ulid())])->save();

            return ['resolved' => true, 'question' => null, 'confirmedMeaning' => ''];
        });
        $job = new ClarifyJournalEntry($state->id, 0);
        $job->handle(app(ProcessJournalClarification::class));
        $job->failed(new RuntimeException('stale'));
        $this->assertSame(Status::Processing, $state->fresh()->status);
        $this->assertNull($state->fresh()->resolved_at);
    }

    public function test_edit_during_check_cannot_resolve_the_changed_draft(): void
    {
        $state = $this->state();
        $baseline = DB::transactionLevel();
        $this->mock(CheckJournalMeaning::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($state, $baseline) {
            $this->assertSame($baseline, DB::transactionLevel(), 'Provider calls must run outside the owner lock.');
            $state->entry->forceFill(['version' => 2, 'body' => '新しい文章。'])->save();

            return ['resolved' => true, 'question' => null, 'confirmedMeaning' => ''];
        });
        (new ClarifyJournalEntry($state->id, 0))->handle(app(ProcessJournalClarification::class));
        $this->assertSame(Status::Failed, $state->fresh()->status);
        $this->assertNull($state->fresh()->resolved_at);
    }

    public function test_provider_failure_is_retryable_and_terminal_failure_is_idempotent(): void
    {
        $state = $this->state();
        $this->mock(CheckJournalMeaning::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('private provider detail'));
        $job = new ClarifyJournalEntry($state->id, 0);
        try {
            $job->handle(app(ProcessJournalClarification::class));
            $this->fail('The queue must record failure.');
        } catch (RuntimeException) {
            $this->assertSame(Status::Failed, $state->fresh()->status);
        }
        $snapshot = $state->fresh()->getAttributes();
        $job->failed(new RuntimeException('duplicate'));
        $this->assertSame($snapshot, $state->fresh()->getAttributes());
        $this->assertStringNotContainsString('private', $state->fresh()->error_message);
    }

    public function test_deleted_entry_skips_check(): void
    {
        $state = $this->state();
        $state->entry->delete();
        $this->mock(CheckJournalMeaning::class)->shouldNotReceive('handle');
        (new ClarifyJournalEntry($state->id, 0))->handle(app(ProcessJournalClarification::class));
        $this->assertSame(Status::Queued, $state->fresh()->status);
    }

    private function state(): JournalClarification
    {
        $entry = JournalEntry::factory()->create(['body' => '車を運転しながら、モーリさんは調べた。']);

        return JournalClarification::factory()->create(['entry_id' => $entry->id, 'source_version' => $entry->version, 'source_body' => $entry->body]);
    }
}
