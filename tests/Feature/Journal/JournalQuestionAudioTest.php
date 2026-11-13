<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\GenerateJournalQuestionAudio;
use App\Domain\Journal\Actions\PrepareJournalQuestion;
use App\Domain\Journal\Actions\SaveJournalTutor;
use App\Domain\Journal\Enums\JournalTutor;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalQuestion;
use App\Jobs\RenderJournalQuestionAudio;
use App\Support\Audio\AudioSpeechGenerationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\Journal\JournalFeedbackFixture;

class JournalQuestionAudioTest extends TestCase
{
    use DatabaseMigrations;

    private int $audioResponseStatus = 200;

    private ?\Closure $duringAudio = null;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([RenderJournalQuestionAudio::class]);
        Storage::fake('local');
        config(['services.fish_audio.api_key' => 'test', 'services.fish_audio.base_url' => 'https://fish.test']);
        Http::fake(['fish.test/*' => function () {
            if ($this->duringAudio !== null) {
                ($this->duringAudio)();
            }

            return Http::response('ID3question', $this->audioResponseStatus);
        }]);
    }

    public function test_question_audio_is_generated_once_with_its_saved_tutor_and_never_changes_narration_default(): void
    {
        [$entry, $analysis, $question] = $this->ready();
        Bus::assertDispatchedTimes(RenderJournalQuestionAudio::class, 1);
        $job = new RenderJournalQuestionAudio($question->id, $question->audio_attempt_id);
        app(SaveJournalTutor::class)->handle($entry->user_id, ['tutorId' => 'ren']);
        $job->handle(app(GenerateJournalQuestionAudio::class));
        $job->handle(app(GenerateJournalQuestionAudio::class));
        $question->refresh();
        $this->assertSame('ready', $question->audio_status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['text'] === $question->text && $request['reference_id'] === substr(JournalTutor::Hana->voiceId(), 10));
        $this->getJson($this->url($entry, $question))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertStreamedContent('ID3question');
        $this->postJson("/api/journal/entries/{$entry->id}/question", ['analysisId' => $analysis->id])->assertOk()->assertJsonPath('data.audio.status', 'ready');
        Bus::assertDispatchedTimes(RenderJournalQuestionAudio::class, 1);
        $this->assertDatabaseCount('journal_audio_preferences', 0);
        $this->signIn();
        $this->getJson($this->url($entry, $question))->assertNotFound();
    }

    public function test_failure_is_retryable_and_an_old_attempt_cannot_fail_or_overwrite_a_new_one(): void
    {
        [$entry, $analysis, $question] = $this->ready();
        $oldToken = $question->audio_attempt_id;
        $job = new RenderJournalQuestionAudio($question->id, $oldToken);
        $job->failed(new RuntimeException('worker ended'));
        $failedAt = $question->fresh()->updated_at;
        $job->failed(new RuntimeException('again'));
        $this->assertTrue($failedAt->eq($question->fresh()->updated_at));
        $this->getJson($this->url($entry, $question))->assertConflict();
        $retry = app(PrepareJournalQuestion::class)->handle($entry->user_id, $entry->id, ['analysisId' => $analysis->id]);
        $this->assertNotSame($oldToken, $retry->audio_attempt_id);
        $job->failed(null);
        $job->handle(app(GenerateJournalQuestionAudio::class));
        Http::assertNothingSent();
        app(GenerateJournalQuestionAudio::class)->handle($retry->id, $retry->audio_attempt_id);
        $this->assertSame('ready', $retry->fresh()->audio_status);
    }

    public function test_entry_deletion_during_synthesis_does_not_publish_orphan_audio(): void
    {
        [$entry, , $question] = $this->ready();
        $this->duringAudio = fn () => $entry->delete();
        app(GenerateJournalQuestionAudio::class)->handle($question->id, $question->audio_attempt_id);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->getJson($this->url($entry, $question))->assertNotFound();
    }

    public function test_queue_failure_keeps_feedback_ready_and_leaves_an_actionable_audio_error(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
        [$entry, $analysis, $question] = $this->ready();
        $this->assertSame('ready', $analysis->fresh()->status->value);
        $this->assertSame('failed', $question->fresh()->audio_status);
        $this->assertNotEmpty($question->fresh()->audio_error);
    }

    public function test_lost_files_and_stale_jobs_can_be_prepared_again_without_changing_the_tutor(): void
    {
        [$entry, $analysis, $question] = $this->ready();
        $question->audio_requested_at = now()->subMinutes(4);
        $question->save();
        $staleToken = $question->audio_attempt_id;
        $question = app(PrepareJournalQuestion::class)->handle($entry->user_id, $entry->id, ['analysisId' => $analysis->id]);
        $this->assertNotSame($staleToken, $question->audio_attempt_id);
        app(GenerateJournalQuestionAudio::class)->handle($question->id, $question->audio_attempt_id);
        Storage::disk('local')->delete($question->fresh()->audio_path);
        $retry = app(PrepareJournalQuestion::class)->handle($entry->user_id, $entry->id, ['analysisId' => $analysis->id]);
        $this->assertSame('queued', $retry->audio_status);
        $this->assertSame(JournalTutor::Hana->snapshot(), $retry->tutor);
    }

    public function test_provider_failure_marks_the_question_retryable_without_saving_audio(): void
    {
        [, , $question] = $this->ready();
        $this->audioResponseStatus = 503;
        try {
            app(GenerateJournalQuestionAudio::class)->handle($question->id, $question->audio_attempt_id);
            $this->fail('Expected synthesis failure');
        } catch (AudioSpeechGenerationException) {
            $this->assertSame('failed', $question->fresh()->audio_status);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_expired_work_is_reported_as_retryable_and_audio_fields_cannot_be_mass_assigned(): void
    {
        [$entry, , $question] = $this->ready();
        $question->audio_requested_at = now()->subMinutes(4);
        $question->save();
        $this->getJson("/api/journal/entries/{$entry->id}/questions/{$question->id}")->assertOk()->assertJsonPath('data.audio.status', 'failed');
        foreach (['audio_status', 'audio_attempt_id', 'audio_requested_at', 'audio_path', 'audio_error'] as $field) {
            $this->assertFalse($question->isFillable($field));
        }
    }

    private function ready(): array
    {
        $user = $this->signIn();
        $entry = JournalEntry::factory()->create(['user_id' => $user->id]);
        $analysis = JournalAnalysis::factory()->create(['entry_id' => $entry->id, 'source_body' => $entry->body, 'status' => 'ready', 'result' => JournalFeedbackFixture::result()]);
        app(SaveJournalTutor::class)->handle($user->id, ['tutorId' => 'hana']);
        $question = app(PrepareJournalQuestion::class)->handle($user->id, $entry->id, ['analysisId' => $analysis->id]);

        return [$entry, $analysis, $question];
    }

    private function url(JournalEntry $entry, JournalQuestion $question): string
    {
        return "/api/journal/entries/{$entry->id}/questions/{$question->id}/audio";
    }
}
