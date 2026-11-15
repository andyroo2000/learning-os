<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Enums\JournalClarificationStatus;
use App\Domain\Journal\Models\JournalClarification;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalClarificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_state_does_not_mark_meaning_as_resolved(): void
    {
        $saved = JournalClarification::factory()->create()->fresh();
        $this->assertSame(JournalClarificationStatus::Queued, $saved->status);
        $this->assertSame(0, $saved->revision);
        $this->assertSame([], $saved->answers);
        $this->assertNull($saved->question);
        $this->assertNull($saved->confirmed_meaning);
        $this->assertNull($saved->resolved_at);
    }

    public function test_questions_answers_and_source_survive_subsequent_entry_edits(): void
    {
        $check = JournalClarification::factory()->create();
        $check->question = ['prompt' => 'Who was driving?', 'choices' => ['Me', 'Molly']];
        $check->answers = [['question' => 'Who was driving?', 'answer' => '私でした。']];
        $check->confirmed_meaning = 'The writer drove while Molly looked up the movie.';
        $check->status = JournalClarificationStatus::Resolved;
        $check->revision = 2;
        $check->resolved_at = '2026-11-15 03:00:00.123456';
        $check->save();
        $check->entry->body = '別の日の話。';
        $check->entry->save();
        $saved = $check->fresh();
        $this->assertSame($check->source_body, $saved->source_body);
        $this->assertSame($check->question, $saved->question);
        $this->assertSame($check->answers, $saved->answers);
        $this->assertSame($check->confirmed_meaning, $saved->confirmed_meaning);
        $this->assertSame(JournalClarificationStatus::Resolved, $saved->status);
        $this->assertSame(2, $saved->revision);
        $this->assertSame('123456', $saved->resolved_at->format('u'));
    }

    #[DataProvider('immutableFields')]
    public function test_source_identity_cannot_be_reassigned(string $field, mixed $value): void
    {
        $check = JournalClarification::factory()->create();
        $check->{$field} = $value;
        $this->expectException(LogicException::class);
        $check->save();
    }

    public static function immutableFields(): array
    {
        return [['entry_id', '01arz3ndektsv4rrffq69g5fav'], ['source_version', 2], ['source_body', '書き換える。']];
    }

    public function test_database_rejects_duplicate_checks_for_a_source_version(): void
    {
        $check = JournalClarification::factory()->create();
        $this->expectException(UniqueConstraintViolationException::class);
        JournalClarification::factory()->create(['entry_id' => $check->entry_id]);
    }

    public function test_different_versions_are_separate_and_account_deletion_removes_both(): void
    {
        $check = JournalClarification::factory()->create();
        JournalClarification::factory()->create(['entry_id' => $check->entry_id, 'source_version' => 2]);
        $this->assertDatabaseCount('journal_clarifications', 2);
        $check->entry->user->delete();
        $this->assertDatabaseCount('journal_clarifications', 0);
    }

    public function test_all_server_owned_fields_reject_mass_assignment(): void
    {
        $check = new JournalClarification;
        foreach (['id', 'entry_id', 'source_version', 'source_body', 'status', 'revision', 'question', 'answers',
            'confirmed_meaning', 'error_message', 'attempt_id', 'processing_started_at', 'resolved_at'] as $field) {
            $this->assertFalse($check->isFillable($field), $field);
        }
        $this->expectException(MassAssignmentException::class);
        $check->fill(['status' => 'resolved']);
    }
}
