<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\CreateJournalConversationEntry;
use App\Domain\Journal\Actions\DeleteJournalEntry;
use App\Domain\Journal\Actions\ListJournalEntries;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Exceptions\JournalVersionConflict;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalQuestions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Journal\JournalFeedbackFixture;

class JournalConversationEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_is_unlisted_and_does_not_change_the_original_document(): void
    {
        [$parent, $analysis, $input] = $this->conversation();
        $before = $analysis->fresh()->getAttributes();
        $child = app(CreateJournalConversationEntry::class)->handle($parent->user_id, strtoupper($parent->id), $input)->fresh();

        $this->assertSame(strtolower($input['id']), $child->id);
        $this->assertSame($parent->id, $child->root_entry_id);
        $this->assertSame(strtolower($input['questionId']), $child->question_id);
        $this->assertSame($parent->user_id, $child->user_id);
        $this->assertSame($input['body'], $child->body);
        $this->assertSame($before, $analysis->fresh()->getAttributes());
        $this->assertSame([$parent->id], app(ListJournalEntries::class)->handle($parent->user_id)['entries']->pluck('id')->all());
    }

    public function test_exact_retry_keeps_one_child_and_changed_payload_conflicts(): void
    {
        [$parent, , $input] = $this->conversation();
        $action = app(CreateJournalConversationEntry::class);
        $child = $action->handle($parent->user_id, $parent->id, $input);
        $retry = $action->handle($parent->user_id, $parent->id, $input);
        $this->assertSame($child->updated_at->toJSON(), $retry->updated_at->toJSON());
        $this->assertDatabaseCount('journal_entries', 2);
        $this->expectException(JournalVersionConflict::class);
        $action->handle($parent->user_id, $parent->id, [...$input, 'body' => '別の答え。']);
    }

    public function test_a_second_device_cannot_create_another_reply_to_the_same_question(): void
    {
        [$parent, , $input] = $this->conversation();
        $action = app(CreateJournalConversationEntry::class);
        $action->handle($parent->user_id, $parent->id, $input);
        $this->expectException(JournalVersionConflict::class);
        $action->handle($parent->user_id, $parent->id, [...$input, 'id' => (string) Str::ulid()]);
    }

    public function test_edited_parent_cannot_accept_a_stale_question(): void
    {
        [$parent, , $input] = $this->conversation();
        $parent->body = '書き直した。';
        $parent->save();
        $this->expectException(JournalVersionConflict::class);
        app(CreateJournalConversationEntry::class)->handle($parent->user_id, $parent->id, $input);
    }

    public function test_deleting_root_removes_child_audio_and_is_retry_safe(): void
    {
        Storage::fake('local');
        [$parent, , $input] = $this->conversation();
        $child = app(CreateJournalConversationEntry::class)->handle($parent->user_id, $parent->id, $input);
        Storage::disk('local')->put('journal/'.$child->id.'/audio.mp3', 'audio');
        $delete = app(DeleteJournalEntry::class);
        $delete->handle($parent->user_id, $parent->id, $parent->version);
        $delete->handle($parent->user_id, $parent->id, $parent->version);
        $this->assertSoftDeleted($parent);
        $this->assertSoftDeleted($child);
        Storage::disk('local')->assertMissing('journal/'.$child->id.'/audio.mp3');
    }

    #[DataProvider('links')]
    public function test_reply_links_cannot_be_reassigned(string $field): void
    {
        [$parent, , $input] = $this->conversation();
        $child = app(CreateJournalConversationEntry::class)->handle($parent->user_id, $parent->id, $input);
        $child->{$field} = null;
        $this->expectException(LogicException::class);
        $child->save();
    }

    public static function links(): array
    {
        return [['root_entry_id'], ['question_id']];
    }

    #[DataProvider('invalidInputs')]
    public function test_direct_action_validates_inputs(string $field, mixed $value): void
    {
        $this->expectException(ValidationException::class);
        app(CreateJournalConversationEntry::class)->handle(1, (string) Str::ulid(), [
            ...['id' => (string) Str::ulid(), 'questionId' => (string) Str::ulid(), 'body' => '答え。'], $field => $value,
        ]);
    }

    public static function invalidInputs(): array
    {
        return [['id', []], ['questionId', 'invalid'], ['body', '　 '], ['body', str_repeat('あ', 20001)], ['body', "a\0"]];
    }

    public function test_another_owner_cannot_answer_or_reuse_a_foreign_id(): void
    {
        [$parent, , $input] = $this->conversation();
        $foreign = JournalEntry::factory()->create();
        $this->expectException(ModelNotFoundException::class);
        app(CreateJournalConversationEntry::class)->handle($foreign->user_id, $parent->id, $input);
    }

    public function test_reply_chain_is_ordered_even_when_creation_timestamps_match(): void
    {
        [$parent, , $input] = $this->conversation();
        $this->travelTo(now(), function () use ($parent, $input): void {
            $action = app(CreateJournalConversationEntry::class);
            $child = $action->handle($parent->user_id, $parent->id, $input);
            $analysis = JournalAnalysis::factory()->for($child, 'entry')->create([
                'source_body' => $child->body, 'status' => JournalAnalysisStatus::Ready, 'result' => JournalFeedbackFixture::result(),
            ]);
            $question = JournalQuestions::record($analysis);
            $next = $action->handle($parent->user_id, $child->id, [
                'id' => '00000000000000000000000001', 'questionId' => $question->id, 'body' => str_repeat('あ', 20000),
            ]);
            $this->assertSame($parent->id, $next->root_entry_id);
            $this->assertSame(2, $next->conversation_position);
            $this->assertSame(str_repeat('あ', 20000), $next->fresh()->body);
        });
    }

    private function conversation(): array
    {
        $parent = JournalEntry::factory()->create(['body' => '映画を見ますた。']);
        $analysis = JournalAnalysis::factory()->for($parent, 'entry')->create([
            'source_body' => $parent->body, 'status' => JournalAnalysisStatus::Ready, 'result' => JournalFeedbackFixture::result(),
        ]);
        $question = JournalQuestions::record($analysis);

        return [$parent, $analysis, ['id' => strtoupper((string) Str::ulid()), 'questionId' => strtoupper($question->id), 'body' => "映像がきれいだった。\n"]];
    }
}
