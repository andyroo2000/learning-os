<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Enums\JournalClarificationStatus as Status;
use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Models\JournalEntry;
use App\Http\Controllers\Api\Journal\JournalClarificationController;
use App\Jobs\ClarifyJournalEntry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalClarificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_poll_answer_and_replacement_question_keep_the_same_session(): void
    {
        Queue::fake();
        $entry = JournalEntry::factory()->for($this->signIn())->create(['body' => '映画を見た。']);
        $url = '/api/journal/entries/'.$entry->id.'/clarification';
        $this->getJson($url)->assertExactJson(['data' => null]);
        $id = $this->postJson($url, ['expectedVersion' => 1])->assertAccepted()->assertJsonPath('data.status', 'queued')->json('data.id');
        Queue::assertPushed(ClarifyJournalEntry::class, fn ($job): bool => $job->attempt->id === $id && $job->attempt->revision === 0);
        $state = JournalClarification::findOrFail($id);
        $question = ['id' => strtolower((string) Str::ulid()), 'prompt' => 'Who was driving?', 'choices' => ['Me', 'Molly']];
        $state->forceFill(['status' => Status::Waiting, 'question' => $question])->save();
        $data = $this->getJson($url)->assertOk()->json('data');
        $this->assertSame(['id', 'sourceVersion', 'revision', 'status', 'question', 'error', 'updatedAt'], array_keys($data));
        $this->assertSame($question, $data['question']);
        $this->assertNull($data['error']);
        $this->withoutMiddleware(TrimStrings::class);
        $this->postJson($url.'/'.$id.'/answer', ['expectedVersion' => 1, 'questionId' => ' '.strtoupper($question['id']).' ', 'answer' => ' I was driving '])
            ->assertAccepted()->assertJsonPath('data.revision', 1)->assertJsonPath('data.question', null);
        $this->assertSame('I was driving', $state->fresh()->answers[0]['answer']);
        Queue::assertPushed(ClarifyJournalEntry::class, fn ($job): bool => $job->attempt->revision === 1);
        $state->refresh()->forceFill(['status' => Status::Waiting, 'question' => [...$question, 'prompt' => 'Which part was unclear?']])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.question.prompt', 'Which part was unclear?');
        $this->assertDatabaseCount('journal_analyses', 0);
    }

    public function test_authentication_and_ownership_apply_to_all_clarification_endpoints(): void
    {
        Queue::fake();
        $entry = JournalEntry::factory()->create(['body' => '映画を見た。']);
        $url = '/api/journal/entries/'.$entry->id.'/clarification';
        $answer = $url.'/'.Str::ulid().'/answer';
        $body = ['expectedVersion' => 1, 'questionId' => (string) Str::ulid(), 'answer' => 'Me'];
        $this->postJson($url, $body)->assertUnauthorized();
        $this->getJson($url)->assertUnauthorized();
        $this->postJson($answer, $body)->assertUnauthorized();
        $this->signIn();
        $this->postJson($url, $body)->assertNotFound();
        $this->getJson($url)->assertNotFound();
        $this->postJson($answer, $body)->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_deleted_after_binding_is_not_returned_by_polling(): void
    {
        $entry = JournalEntry::factory()->for($this->signIn())->create();
        $entry->fresh()->delete();
        $this->expectException(ModelNotFoundException::class);
        app(JournalClarificationController::class)->show($entry);
    }

    public function test_stale_draft_conflicts_and_old_session_is_not_shown_for_new_draft(): void
    {
        Queue::fake();
        $entry = JournalEntry::factory()->for($this->signIn())->create(['body' => '映画を見た。']);
        $url = '/api/journal/entries/'.$entry->id.'/clarification';
        $this->postJson($url, ['expectedVersion' => 1])->assertAccepted();
        $entry->forceFill(['version' => 2])->save();
        $this->postJson($url, ['expectedVersion' => 1])->assertConflict()->assertJsonPath('reason', 'journal_version_conflict');
        $this->getJson($url)->assertExactJson(['data' => null]);
    }

    #[DataProvider('invalidAnswerPayloads')]
    public function test_invalid_answers_fail_before_dispatch(array $payload): void
    {
        Queue::fake();
        $entry = JournalEntry::factory()->for($this->signIn())->create(['body' => '映画を見た。']);
        $this->postJson('/api/journal/entries/'.$entry->id.'/clarification/'.Str::ulid().'/answer', $payload)->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public static function invalidAnswerPayloads(): array
    {
        $valid = ['expectedVersion' => 1, 'questionId' => '01arz3ndektsv4rrffq69g5fav', 'answer' => 'Me'];

        return [[[]], [[...$valid, 'answer' => []]], [[...$valid, 'questionId' => []]],
            [[...$valid, 'answer' => ' ']], [[...$valid, 'answer' => str_repeat('あ', 2001)]],
            [[...$valid, 'answer' => "bad\0text"]], [[...$valid, 'expectedVersion' => 0]]];
    }

    public function test_start_has_a_separate_rate_limit_and_polling_remains_available(): void
    {
        Queue::fake();
        $entry = JournalEntry::factory()->for($this->signIn())->create(['body' => '映画を見た。']);
        $url = '/api/journal/entries/'.$entry->id.'/clarification';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson($url, ['expectedVersion' => 1])->assertAccepted();
        }
        $this->postJson($url, ['expectedVersion' => 1])->assertTooManyRequests();
        $this->getJson($url)->assertOk();
    }
}
