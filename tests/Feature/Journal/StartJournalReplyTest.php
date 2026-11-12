<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\StartJournalReply;
use App\Domain\Journal\Data\JournalReplyInput;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalReply;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class StartJournalReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_retries_keep_the_same_pending_answer_without_mutating_original_writing(): void
    {
        [$analysis, $input] = $this->context();
        $action = app(StartJournalReply::class);
        $reply = $action->handle($analysis->entry->user_id, $input);
        $again = $action->handle($analysis->entry->user_id, $input);
        $this->assertSame($reply->id, $again->id);
        $this->assertSame($input->answer, $reply->answer);
        $this->assertSame($reply->id, $analysis->fresh()->pending_reply_id);
        $this->assertSame($analysis->source_body, $analysis->entry->fresh()->body);
        $this->assertDatabaseCount('journal_replies', 1);
    }

    public function test_only_one_pending_answer_is_allowed(): void
    {
        [$analysis, $input] = $this->context();
        app(StartJournalReply::class)->handle($analysis->entry->user_id, $input);
        $next = new JournalReplyInput([...$this->input($analysis), 'id' => (string) Str::ulid()]);
        $this->expectException(JournalVersionConflict::class);
        app(StartJournalReply::class)->handle($analysis->entry->user_id, $next);
    }

    public function test_editing_an_answer_uses_its_parent_and_keeps_the_old_answer(): void
    {
        [$analysis] = $this->context();
        $old = JournalReply::factory()->create(['analysis_id' => $analysis->id, 'status' => JournalAnalysisStatus::Ready]);
        $analysis->current_reply_id = $old->id;
        $analysis->reply_revision = 1;
        $analysis->save();
        $input = new JournalReplyInput([...$this->input($analysis), 'expectedRevision' => 1, 'replace' => true]);
        $reply = app(StartJournalReply::class)->handle($analysis->entry->user_id, $input);
        $this->assertNull($reply->parent_id);
        $this->assertTrue($reply->replaces_answer);
        $this->assertDatabaseHas('journal_replies', ['id' => $old->id, 'answer' => $old->answer]);
    }

    public function test_foreign_analyses_are_hidden(): void
    {
        [$analysis, $input] = $this->context();
        $this->expectException(ModelNotFoundException::class);
        app(StartJournalReply::class)->handle(User::factory()->create()->id, $input);
    }

    public function test_changed_entries_reject_followups_from_old_feedback(): void
    {
        [$analysis, $input] = $this->context();
        $analysis->entry->body = '別の日。';
        $analysis->entry->save();
        $this->expectException(JournalVersionConflict::class);
        app(StartJournalReply::class)->handle($analysis->entry->user_id, $input);
    }

    public function test_whitespace_only_answers_are_rejected(): void
    {
        [$analysis] = $this->context();
        $this->expectException(ValidationException::class);
        new JournalReplyInput([...$this->input($analysis), 'answer' => " \n　"]);
    }

    public function test_original_reply_answers_are_immutable(): void
    {
        $reply = JournalReply::factory()->create();
        $reply->answer = '変更';
        $this->expectException(LogicException::class);
        $reply->save();
    }

    public function test_account_deletion_cascades_to_followup_answers(): void
    {
        $reply = JournalReply::factory()->create();
        $reply->analysis->entry->user->delete();
        $this->assertDatabaseCount('journal_replies', 0);
    }

    private function context(): array
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見た。']);
        $analysis = JournalAnalysis::factory()->create(['entry_id' => $entry->id,
            'source_body' => $entry->body, 'status' => JournalAnalysisStatus::Ready]);

        return [$analysis, new JournalReplyInput($this->input($analysis))];
    }

    private function input(JournalAnalysis $analysis): array
    {
        return ['id' => (string) Str::ulid(), 'analysisId' => strtoupper($analysis->id),
            'answer' => " 友達と見ました。\n", 'expectedRevision' => 0, 'replace' => false];
    }
}
