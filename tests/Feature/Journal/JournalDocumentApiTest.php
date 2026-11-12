<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\ProcessJournalReply;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Services\GenerateJournalFeedback;
use App\Domain\Journal\Support\JournalAudioVersion;
use App\Jobs\AnalyzeJournalReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\Journal\JournalFeedbackFixture;

class JournalDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_are_persisted_versioned_and_used_by_audio_without_changing_original_writing(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $url = '/api/journal/entries/'.$entry->id;
        $this->putJson($url.'/suggestion', $input)->assertOk()
            ->assertJsonPath('data.correctedDocument.text', '映画を見ますた。')
            ->assertJsonPath('data.correctedDocument.suggestions.0.applied', false)
            ->assertJsonPath('data.replyRevision', 1);
        $this->getJson($url.'/analysis')->assertOk()->assertJsonPath('data.correctedDocument.text', '映画を見ますた。');
        $this->assertSame('映画を見ますた。', JournalAudioVersion::text($entry, ['analysisId' => $analysis->id, 'expectedReplyRevision' => 1]));
        $this->assertSame($entry->body, $entry->fresh()->body);
        $this->putJson($url.'/suggestion', $input)->assertConflict();
        $input['expectedRevision'] = 1;
        $this->putJson($url.'/suggestion', $input)->assertOk()->assertJsonPath('data.replyRevision', 1);
        $this->putJson($url.'/suggestion', [...$input, 'applied' => true])->assertOk()
            ->assertJsonPath('data.correctedDocument.text', '映画を見ました。')->assertJsonPath('data.replyRevision', 2);
    }

    public function test_changes_are_private_and_reject_stale_pending_or_unknown_suggestions(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $url = '/api/journal/entries/'.$entry->id.'/suggestion';
        $this->putJson($url, [...$input, 'suggestionId' => $analysis->id.':9'])->assertUnprocessable();
        $this->putJson($url, [...$input, 'applied' => 'false'])->assertUnprocessable();
        $analysis->pending_reply_id = strtolower((string) Str::ulid());
        $analysis->save();
        $this->putJson($url, $input)->assertConflict();
        $analysis->pending_reply_id = null;
        $analysis->save();
        $entry->body = 'Different draft';
        $entry->save();
        $this->putJson($url, $input)->assertConflict();
        $this->signIn();
        $this->putJson($url, $input)->assertNotFound();
    }

    public function test_reply_generation_and_undo_preserve_the_users_suggestion_choices(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $url = '/api/journal/entries/'.$entry->id;
        $this->putJson($url.'/suggestion', $input)->assertOk();
        $replyId = strtolower((string) Str::ulid());
        $this->putJson($url.'/replies/'.$replyId, ['analysisId' => $analysis->id,
            'answer' => 'はい。', 'expectedRevision' => 1, 'replace' => false])->assertAccepted();
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()
            ->withArgs(fn ($answer, $context) => $context['diary'] === '映画を見ますた。' && ! isset($context['document']))
            ->andReturn(JournalFeedbackFixture::result());
        (new AnalyzeJournalReply($replyId))->handle(app(ProcessJournalReply::class));
        $this->getJson($url.'/analysis')->assertOk()->assertJsonPath('data.correctedDocument.text', "映画を見ますた。\n\n映画を見ました。");
        $this->putJson($url.'/suggestion', [...$input, 'expectedRevision' => 2, 'applied' => true])->assertOk();
        $this->postJson($url.'/undo', ['analysisId' => $analysis->id, 'expectedRevision' => 3])->assertOk()
            ->assertJsonPath('data.correctedDocument.text', '映画を見ました。')
            ->assertJsonPath('data.correctedDocument.suggestions.0.applied', true);
    }

    public function test_replacing_an_answer_keeps_choices_made_after_the_original_answer(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $url = '/api/journal/entries/'.$entry->id;
        $first = strtolower((string) Str::ulid());
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->andReturn(JournalFeedbackFixture::result());
        $this->putJson($url.'/replies/'.$first, ['analysisId' => $analysis->id,
            'answer' => 'はい。', 'expectedRevision' => 0, 'replace' => false])->assertAccepted();
        (new AnalyzeJournalReply($first))->handle(app(ProcessJournalReply::class));
        $this->putJson($url.'/suggestion', [...$input, 'expectedRevision' => 1])->assertOk();
        $second = strtolower((string) Str::ulid());
        $this->putJson($url.'/replies/'.$second, ['analysisId' => $analysis->id,
            'answer' => 'いいえ。', 'expectedRevision' => 2, 'replace' => true])->assertAccepted();
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()
            ->withArgs(fn ($answer, $context) => $context['diary'] === '映画を見ますた。')
            ->andReturn(JournalFeedbackFixture::result());
        (new AnalyzeJournalReply($second))->handle(app(ProcessJournalReply::class));
        $this->getJson($url.'/analysis')->assertOk()
            ->assertJsonPath('data.correctedDocument.text', "映画を見ますた。\n\n映画を見ました。")
            ->assertJsonMissingPath('data.result.document')
            ->assertJsonMissingPath('data.currentReply.result.document');
    }

    private function ready(): array
    {
        Queue::fake();
        $entry = JournalEntry::factory()->for($this->signIn())->create();
        $feedback = JournalFeedbackFixture::result();
        $feedback['editVersion'] = 1;
        $feedback['segments'] = [['text' => '映画を見ますた。', 'reading' => '映画[えいが]を見[み]ますた。',
            'kind' => 'correction', 'replacement' => '映画を見ました。', 'replacementReading' => '映画[えいが]を見[み]ました。']];
        $analysis = new JournalAnalysis;
        $analysis->entry_id = $entry->id;
        $analysis->source_version = 1;
        $analysis->source_body = $entry->body;
        $analysis->status = JournalAnalysisStatus::Ready;
        $analysis->result = $feedback;
        $analysis->save();

        return [$entry, $analysis, ['analysisId' => strtoupper($analysis->id), 'expectedRevision' => 0,
            'suggestionId' => strtoupper($analysis->id).':0', 'applied' => false]];
    }
}
