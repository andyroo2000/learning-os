<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\ProcessJournalAnalysis;
use App\Domain\Journal\Actions\StartJournalAnalysis;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Enums\JournalClarificationStatus as Status;
use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Services\GenerateJournalFeedback;
use App\Jobs\AnalyzeJournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Unit\Journal\JournalFeedbackFixture;

class JournalClarifiedFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_unresolved_questions_block_submission_with_or_without_an_explicit_id(): void
    {
        Queue::fake();
        $entry = JournalEntry::factory()->for($this->signIn())->create(['body' => '映画を見ますた。']);
        $state = $this->state($entry, Status::Waiting);
        $url = '/api/journal/entries/'.$entry->id.'/submit';
        $this->postJson($url, ['expectedVersion' => 1, 'clarificationId' => $state->id])->assertUnprocessable()->assertJsonValidationErrors('clarificationId');
        $this->postJson($url, ['expectedVersion' => 1])->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->expectException(ValidationException::class);
        app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1, $state->id);
    }

    public function test_resolved_answers_reach_existing_feedback_and_the_result_shape_is_unchanged(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ますた。']);
        $state = $this->state($entry, Status::Resolved);
        $analysis = app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1, strtoupper($state->id));
        $this->assertSame($state->id, $analysis->clarification_id);
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->with($entry->body, [
            'mode' => 'journal', 'clarification' => ['answers' => [['question' => 'Who was driving?', 'answer' => 'I was driving']], 'confirmedMeaning' => 'The writer drove while Molly searched.'],
        ])->andReturn(JournalFeedbackFixture::result());
        (new AnalyzeJournalEntry($analysis->id))->handle(app(ProcessJournalAnalysis::class));
        $this->assertSame(JournalAnalysisStatus::Ready, $analysis->fresh()->status);
        $this->assertSame(JournalFeedbackFixture::result(), $analysis->fresh()->result);
    }

    public function test_new_clarification_replaces_previously_generated_feedback_for_the_same_version(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ますた。', 'title' => 'My title']);
        $start = app(StartJournalAnalysis::class);
        $old = $start->handle($entry->user_id, $entry->id, 1);
        $old->forceFill(['status' => JournalAnalysisStatus::Ready, 'result' => JournalFeedbackFixture::result()])->save();
        $state = $this->state($entry, Status::Resolved);
        $new = $start->handle($entry->user_id, $entry->id, 1, $state->id);
        $this->assertSame($old->id, $new->id);
        $this->assertSame(JournalAnalysisStatus::Queued, $new->status);
        $this->assertSame($state->id, $new->clarification_id);
        $this->assertNull($new->result);
    }

    public function test_clarification_started_during_generation_prevents_old_feedback_from_committing(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ますた。']);
        $analysis = app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1);
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($entry) {
            $this->state($entry, Status::Waiting);

            return JournalFeedbackFixture::result();
        });
        (new AnalyzeJournalEntry($analysis->id))->handle(app(ProcessJournalAnalysis::class));
        $this->assertSame(JournalAnalysisStatus::Failed, $analysis->fresh()->status);
        $this->assertNull($analysis->fresh()->result);
        $this->assertNull($entry->fresh()->title);
    }

    public function test_http_rejects_an_unrelated_or_malformed_clarification_id(): void
    {
        Queue::fake();
        $entry = JournalEntry::factory()->for($this->signIn())->create(['body' => '映画を見ますた。']);
        $url = '/api/journal/entries/'.$entry->id.'/submit';
        $this->postJson($url, ['expectedVersion' => 1, 'clarificationId' => (string) Str::ulid()])->assertConflict();
        $this->postJson($url, ['expectedVersion' => 1, 'clarificationId' => []])->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    private function state(JournalEntry $entry, Status $status): JournalClarification
    {
        return JournalClarification::factory()->create([
            'entry_id' => $entry->id, 'source_version' => $entry->version, 'source_body' => $entry->body, 'status' => $status,
            'answers' => [['questionId' => strtolower((string) Str::ulid()), 'question' => 'Who was driving?', 'answer' => 'I was driving']],
            'confirmed_meaning' => 'The writer drove while Molly searched.',
            'resolved_at' => $status === Status::Resolved ? now() : null,
        ]);
    }
}
