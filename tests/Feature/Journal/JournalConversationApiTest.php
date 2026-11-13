<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\CreateJournalConversationEntry;
use App\Domain\Journal\Actions\ProcessJournalAnalysis;
use App\Domain\Journal\Actions\StartJournalAnalysis;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Enums\JournalTutor;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Services\GenerateJournalFeedback;
use App\Domain\Journal\Support\JournalConversationContext;
use App\Domain\Journal\Support\JournalQuestions;
use App\Jobs\AnalyzeJournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\Journal\JournalFeedbackFixture;

class JournalConversationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_is_idempotent_unlisted_and_uses_normal_analysis_submission(): void
    {
        [$parent, $question] = $this->conversation();
        $input = ['id' => strtoupper((string) Str::ulid()), 'questionId' => strtoupper($question->id), 'body' => "  友達と見た。\n"];
        $url = '/api/journal/entries/'.strtoupper($parent->id).'/conversation';
        $child = $this->postJson($url, $input)->assertCreated()->assertJsonPath('data.rootEntryId', $parent->id)->json('data');
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.id', $child['id']);
        $this->assertSame($input['body'], JournalEntry::findOrFail($child['id'])->body);
        $this->getJson('/api/journal/entries')->assertJsonCount(1, 'data');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.question.id', $question->id)
            ->assertJsonPath('data.0.parentEntryId', $parent->id)->assertJsonPath('data.0.analysis', null);
        Queue::fake();
        $this->postJson('/api/journal/entries/'.$child['id'].'/submit', ['expectedVersion' => 1])->assertAccepted();
        Queue::assertPushed(AnalyzeJournalEntry::class);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.analysis.status', 'queued');
    }

    public function test_child_can_be_edited_without_changing_its_root_or_answered_question(): void
    {
        [$parent, $question] = $this->conversation();
        $child = $this->postJson('/api/journal/entries/'.$parent->id.'/conversation', [
            'id' => (string) Str::ulid(), 'questionId' => $question->id, 'body' => '友達と見た。',
        ])->assertCreated()->json('data');
        $this->putJson('/api/journal/entries/'.$child['id'], [
            'title' => null, 'body' => '妻と見た。', 'entryDate' => $child['entryDate'], 'expectedVersion' => 1,
            'rootEntryId' => (string) Str::ulid(), 'questionId' => (string) Str::ulid(),
        ])->assertOk()->assertJsonPath('data.rootEntryId', $parent->id)->assertJsonPath('data.body', '妻と見た。');
        $this->assertSame($question->id, JournalEntry::findOrFail($child['id'])->question_id);
        $this->assertSame('映画を見ますた。', $parent->fresh()->body);
        $this->getJson('/api/journal/entries/'.$child['id'].'/conversation')->assertNotFound();
    }

    public function test_other_accounts_cannot_read_or_answer_the_conversation(): void
    {
        [$parent, $question] = $this->conversation();
        $this->signIn();
        $url = '/api/journal/entries/'.$parent->id.'/conversation';
        $this->getJson($url)->assertNotFound();
        $this->postJson($url, ['id' => (string) Str::ulid(), 'questionId' => $question->id, 'body' => '答え。'])->assertNotFound();
    }

    public function test_invalid_answers_return_validation_errors(): void
    {
        [$parent, $question] = $this->conversation();
        $this->postJson('/api/journal/entries/'.$parent->id.'/conversation', [
            'id' => [], 'questionId' => $question->id, 'body' => '　 ',
        ])->assertUnprocessable()->assertJsonValidationErrors(['id', 'body']);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_analysis_uses_frozen_question_context_without_appending_to_parent(): void
    {
        [$parent, $question] = $this->conversation();
        $child = $this->postJson('/api/journal/entries/'.$parent->id.'/conversation', [
            'id' => (string) Str::ulid(), 'questionId' => $question->id, 'body' => '友達と見た。',
        ])->assertCreated()->json('data');
        $analysis = app(StartJournalAnalysis::class)->handle($parent->user_id, $child['id'], 1);
        $before = $parent->latestAnalysis->getAttributes();
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->with('友達と見た。', [
            'mode' => 'conversation', 'diary' => $parent->body, 'previousAnswer' => $parent->body, 'question' => $question->text,
        ])->andReturn(JournalFeedbackFixture::result());
        (new AnalyzeJournalEntry($analysis->id))->handle(app(ProcessJournalAnalysis::class));
        $this->assertSame(JournalAnalysisStatus::Ready, $analysis->fresh()->status);
        $this->assertSame($before, $parent->latestAnalysis->fresh()->getAttributes());
        $this->getJson('/api/journal/entries/'.$parent->id.'/conversation')->assertOk()
            ->assertJsonPath('data.0.analysis.status', 'ready')->assertJsonPath('data.0.entry.body', '友達と見た。');
    }

    public function test_historical_question_audio_retries_with_its_original_tutor_after_parent_edits(): void
    {
        Queue::fake();
        [$parent, $question] = $this->conversation();
        $question->tutor = JournalTutor::Hana->snapshot();
        $question->audio_status = 'failed';
        $question->save();
        $parent->body = '書き直した。';
        $parent->save();
        $url = '/api/journal/entries/'.$parent->id.'/questions/'.strtoupper($question->id).'/retry';
        $this->postJson($url)->assertOk()->assertJsonPath('data.tutor.id', 'hana')->assertJsonPath('data.audio.status', 'queued');
        $this->assertSame($question->text, $question->fresh()->text);
        $this->signIn();
        $this->postJson($url)->assertNotFound();
    }

    public function test_nested_context_uses_the_original_diary_and_immediate_parent_snapshot(): void
    {
        [$root, $firstQuestion] = $this->conversation();
        $child = app(CreateJournalConversationEntry::class)->handle($root->user_id, $root->id, [
            'id' => (string) Str::ulid(), 'questionId' => $firstQuestion->id, 'body' => '妻と見た。',
        ]);
        $parentAnalysis = JournalAnalysis::factory()->for($child, 'entry')->create([
            'source_body' => $child->body, 'status' => JournalAnalysisStatus::Ready, 'result' => JournalFeedbackFixture::result(),
        ]);
        $nextQuestion = JournalQuestions::record($parentAnalysis);
        $next = app(CreateJournalConversationEntry::class)->handle($root->user_id, $child->id, [
            'id' => (string) Str::ulid(), 'questionId' => $nextQuestion->id, 'body' => '妻も好きだった。',
        ]);
        $analysis = app(StartJournalAnalysis::class)->handle($root->user_id, $next->id, 1);
        $root->body = '旅行に行った。';
        $root->save();
        $context = JournalConversationContext::forAnalysis($analysis->id);
        $this->assertSame('映画を見ますた。', $context['diary']);
        $this->assertSame('妻と見た。', $context['previousAnswer']);
        $this->assertSame($nextQuestion->text, $context['question']);
    }

    private function conversation(): array
    {
        $user = $this->signIn();
        $parent = JournalEntry::factory()->create(['user_id' => $user->id, 'body' => '映画を見ますた。']);
        $analysis = JournalAnalysis::factory()->for($parent, 'entry')->create([
            'source_body' => $parent->body, 'status' => JournalAnalysisStatus::Ready, 'result' => JournalFeedbackFixture::result(),
        ]);

        return [$parent, JournalQuestions::record($analysis)];
    }
}
