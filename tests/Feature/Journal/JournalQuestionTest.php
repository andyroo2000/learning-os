<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\PrepareJournalQuestion;
use App\Domain\Journal\Actions\SaveJournalTutor;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Enums\JournalTutor;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalQuestion;
use App\Domain\Journal\Models\JournalReply;
use App\Domain\Journal\Support\JournalQuestions;
use App\Domain\Journal\Support\JournalWriteTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;
use Tests\Unit\Journal\JournalFeedbackFixture;

class JournalQuestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_choice_assigns_the_waiting_question_and_later_choices_only_affect_new_questions(): void
    {
        [$entry, $analysis] = $this->ready();
        $url = "/api/journal/entries/{$entry->id}/question";
        $first = $this->postJson($url, ['analysisId' => strtoupper($analysis->id)])->assertOk()->assertJsonFragment(['tutor' => null])->json('data.id');
        app(SaveJournalTutor::class)->handle($entry->user_id, ['tutorId' => 'hana']);
        $this->postJson($url, ['analysisId' => $analysis->id])->assertOk()->assertJsonPath('data.id', $first)->assertJsonPath('data.tutor.id', 'hana');
        $this->assertSame(JournalTutor::Hana->snapshot(), JournalQuestion::findOrFail($first)->tutor);
        app(SaveJournalTutor::class)->handle($entry->user_id, ['tutorId' => 'ren']);
        $this->postJson($url, ['analysisId' => $analysis->id])->assertOk()->assertJsonPath('data.tutor.id', 'hana');
        $reply = JournalReply::factory()->create(['analysis_id' => $analysis->id, 'status' => 'ready', 'result' => JournalFeedbackFixture::result()]);
        $analysis->current_reply_id = $reply->id;
        $analysis->save();
        $next = $this->postJson($url, ['analysisId' => $analysis->id, 'replyId' => $reply->id])->assertOk()->assertJsonPath('data.tutor.id', 'ren')->json('data.id');
        $this->assertNotSame($first, $next);
        $this->getJson("/api/journal/entries/{$entry->id}/questions/$first")->assertOk()->assertJsonPath('data.tutor.id', 'hana')->assertJsonMissingPath('data.tutor.voiceId');
        $this->assertDatabaseCount('journal_questions', 2);
        $this->assertDatabaseCount('journal_audio_preferences', 0);
    }

    public function test_generation_records_the_current_tutor_and_readings_before_the_question_is_opened(): void
    {
        [$entry, $analysis] = $this->ready();
        app(SaveJournalTutor::class)->handle($entry->user_id, ['tutorId' => 'ren']);
        $question = JournalWriteTransaction::run($entry->user_id, fn () => JournalQuestions::record($analysis));
        app(SaveJournalTutor::class)->handle($entry->user_id, ['tutorId' => 'hana']);
        $result = $this->postJson("/api/journal/entries/{$entry->id}/question", ['analysisId' => $analysis->id])->assertOk();
        $result->assertJsonPath('data.id', $question->id)->assertJsonPath('data.tutor.id', 'ren')
            ->assertJsonPath('data.reading', $analysis->result['questionReading'])->assertJsonPath('data.translation', $analysis->result['questionTranslation']);
    }

    public function test_other_owners_stale_sources_and_deleted_entries_cannot_prepare_or_read_questions(): void
    {
        [$entry, $analysis] = $this->ready();
        $url = "/api/journal/entries/{$entry->id}/question";
        $id = $this->postJson($url, ['analysisId' => $analysis->id])->assertOk()->json('data.id');
        $this->postJson($url, ['analysisId' => $analysis->id, 'replyId' => (string) Str::ulid()])->assertConflict();
        $this->signIn();
        $this->postJson($url, ['analysisId' => $analysis->id])->assertNotFound();
        $this->getJson("/api/journal/entries/{$entry->id}/questions/$id")->assertNotFound();
        $this->actingAs($entry->user);
        $entry->delete();
        $this->postJson($url, ['analysisId' => $analysis->id])->assertNotFound();
        $this->getJson("/api/journal/entries/{$entry->id}/questions/$id")->assertNotFound();
    }

    public function test_model_rejects_reassignment_and_protects_server_owned_fields(): void
    {
        [$entry, $analysis] = $this->ready();
        app(SaveJournalTutor::class)->handle($entry->user_id, ['tutorId' => 'hana']);
        $question = app(PrepareJournalQuestion::class)->handle($entry->user_id, $entry->id, ['analysisId' => $analysis->id]);
        foreach (['id', 'analysis_id', 'source_key', 'text', 'reading', 'translation', 'tutor'] as $field) {
            $this->assertFalse($question->isFillable($field));
        }
        $question->tutor = JournalTutor::Ren->snapshot();
        $this->expectException(LogicException::class);
        $question->save();
    }

    public function test_unready_and_incomplete_legacy_feedback_have_actionable_errors(): void
    {
        [$entry, $analysis] = $this->ready();
        $url = "/api/journal/entries/{$entry->id}/question";
        $analysis->status = JournalAnalysisStatus::Processing;
        $analysis->save();
        $this->postJson($url, ['analysisId' => $analysis->id])->assertConflict();
        $analysis->status = JournalAnalysisStatus::Ready;
        $analysis->result = ['question' => 'どうでしたか。'];
        $analysis->save();
        $this->postJson($url, ['analysisId' => $analysis->id])->assertUnprocessable()->assertJsonValidationErrors('questionReading');
        $this->assertDatabaseCount('journal_questions', 0);
    }

    public function test_question_ids_are_scoped_to_the_requested_entry_even_for_the_same_owner(): void
    {
        [$entry, $analysis] = $this->ready();
        $question = app(PrepareJournalQuestion::class)->handle($entry->user_id, $entry->id, ['analysisId' => $analysis->id]);
        $other = JournalEntry::factory()->create(['user_id' => $entry->user_id]);
        $this->getJson("/api/journal/entries/{$other->id}/questions/{$question->id}")->assertNotFound();
        $this->postJson("/api/journal/entries/{$other->id}/question", ['analysisId' => $analysis->id])->assertNotFound();
    }

    public function test_direct_calls_validate_ids_before_queries(): void
    {
        $this->expectException(ValidationException::class);
        app(PrepareJournalQuestion::class)->handle(1, 'invalid', ['analysisId' => ['invalid']]);
    }

    private function ready(): array
    {
        $user = $this->signIn();
        $entry = JournalEntry::factory()->create(['user_id' => $user->id]);
        $analysis = JournalAnalysis::factory()->create(['entry_id' => $entry->id, 'source_body' => $entry->body,
            'status' => 'ready', 'result' => JournalFeedbackFixture::result()]);

        return [$entry, $analysis];
    }
}
