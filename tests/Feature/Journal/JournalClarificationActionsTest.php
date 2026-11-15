<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\AnswerJournalClarification;
use App\Domain\Journal\Actions\DispatchJournalClarification;
use App\Domain\Journal\Actions\StartJournalClarification;
use App\Domain\Journal\Data\JournalClarificationAnswer;
use App\Domain\Journal\Enums\JournalClarificationStatus as Status;
use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Models\JournalEntry;
use App\Jobs\ClarifyJournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class JournalClarificationActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_is_idempotent_and_preserves_an_unanswered_question(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ました。']);
        $start = app(StartJournalClarification::class);
        $state = $start->handle($entry->user_id, ' '.strtoupper($entry->id).' ', 1);
        $this->assertSame($entry->body, $state->source_body);
        $this->assertSame(1, $state->source_version);
        $question = $this->waiting($state);
        $again = $start->handle($entry->user_id, $entry->id, 1);
        $this->assertSame($state->id, $again->id);
        $this->assertSame($question, $again->question);
        $this->assertSame(Status::Waiting, $again->status);
        $this->assertDatabaseCount('journal_clarifications', 1);
    }

    public function test_answer_retries_do_not_append_twice_or_erase_the_next_question(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ました。']);
        $state = app(StartJournalClarification::class)->handle($entry->user_id, $entry->id, 1);
        $question = $this->waiting($state);
        $input = new JournalClarificationAnswer(strtoupper($state->id), strtoupper($question['id']), ' I was driving ');
        $action = app(AnswerJournalClarification::class);
        $answered = $action->handle($entry->user_id, $entry->id, 1, $input);
        $this->assertSame(Status::Queued, $answered->status);
        $this->assertSame(1, $answered->revision);
        $this->assertNull($answered->question);
        $nextQuestion = $this->waiting($answered);
        $again = $action->handle($entry->user_id, $entry->id, 1, $input);
        $this->assertCount(1, $again->answers);
        $this->assertSame('I was driving', $again->answers[0]['answer']);
        $this->assertSame($nextQuestion, $again->question);
        $this->assertSame(Status::Waiting, $again->status);
        $this->expectException(JournalVersionConflict::class);
        $action->handle($entry->user_id, $entry->id, 1, new JournalClarificationAnswer($state->id, $question['id'], 'Molly'));
    }

    public function test_answer_cannot_apply_to_an_edited_entry(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ました。']);
        $state = app(StartJournalClarification::class)->handle($entry->user_id, $entry->id, 1);
        $question = $this->waiting($state);
        $entry->forceFill(['version' => 2, 'body' => '新しい文章。'])->save();
        try {
            app(AnswerJournalClarification::class)->handle($entry->user_id, $entry->id, 1, new JournalClarificationAnswer($state->id, $question['id'], 'Me'));
            $this->fail('Old draft was accepted.');
        } catch (JournalVersionConflict) {
            $this->assertSame([], $state->fresh()->answers);
        }
        $new = app(StartJournalClarification::class)->handle($entry->user_id, $entry->id, 2);
        $this->assertNotSame($state->id, $new->id);
        $this->assertSame('新しい文章。', $new->source_body);
    }

    public function test_cross_owner_start_and_answer_hide_the_entry(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ました。']);
        $other = User::factory()->create();
        try {
            app(StartJournalClarification::class)->handle($other->id, $entry->id, 1);
            $this->fail('Another owner was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('journal_clarifications', 0);
        }
        $state = app(StartJournalClarification::class)->handle($entry->user_id, $entry->id, 1);
        $question = $this->waiting($state);
        $this->expectException(ModelNotFoundException::class);
        app(AnswerJournalClarification::class)->handle($other->id, $entry->id, 1, new JournalClarificationAnswer($state->id, $question['id'], 'Me'));
    }

    public function test_dispatch_failure_is_retryable_without_losing_prior_answers(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ました。']);
        $state = app(StartJournalClarification::class)->handle($entry->user_id, $entry->id, 1);
        $question = $this->waiting($state);
        $answered = app(AnswerJournalClarification::class)->handle($entry->user_id, $entry->id, 1, new JournalClarificationAnswer($state->id, $question['id'], 'Me'));
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue offline'));
        $failed = app(DispatchJournalClarification::class)->handle($answered);
        $this->assertSame(Status::Failed, $failed->status);
        $retry = app(StartJournalClarification::class)->handle($entry->user_id, $entry->id, 1);
        $this->assertSame(Status::Queued, $retry->status);
        $this->assertSame($answered->answers, $retry->answers);
        Bus::fake();
        app(DispatchJournalClarification::class)->handle($retry);
        Bus::assertDispatched(ClarifyJournalEntry::class, fn ($job): bool => $job->attempt->revision === 1);
    }

    #[DataProvider('invalidAnswers')]
    public function test_answer_input_rejects_malformed_or_oversized_text(string $text): void
    {
        $this->expectException(ValidationException::class);
        new JournalClarificationAnswer((string) Str::ulid(), (string) Str::ulid(), $text);
    }

    public static function invalidAnswers(): array
    {
        return [[' '], ["bad\0text"], [str_repeat('あ', 2001)]];
    }

    private function waiting(JournalClarification $state): array
    {
        $question = ['id' => strtolower((string) Str::ulid()), 'prompt' => 'Who was driving?', 'choices' => ['Me', 'Molly']];
        $state->forceFill(['status' => Status::Waiting, 'question' => $question])->save();

        return $question;
    }
}
