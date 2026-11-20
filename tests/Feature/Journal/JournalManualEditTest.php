<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\ProcessJournalReading;
use App\Domain\Journal\Actions\ProcessJournalReply;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Services\GenerateJournalFeedback;
use App\Domain\Study\Services\StudyCaptureReadingGenerator;
use App\Jobs\AnalyzeJournalReply;
use App\Jobs\GenerateJournalReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\Journal\JournalFeedbackFixture;

class JournalManualEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_edits_are_saved_before_readings_run_without_rewriting_or_advancing_the_revision_again(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $this->mock(StudyCaptureReadingGenerator::class)->shouldNotReceive('generate');
        $this->putJson($this->url($entry), $input)->assertOk()
            ->assertJsonPath('data.correctedDocument.text', $input['text'])
            ->assertJsonPath('data.correctedDocument.reading', $input['text'])
            ->assertJsonPath('data.correctedDocument.readingStatus', 'pending')
            ->assertJsonMissingPath('data.result.documentReadings')
            ->assertJsonPath('data.replyRevision', 1);
        Queue::assertPushed(GenerateJournalReading::class, 1);
        $this->mock(StudyCaptureReadingGenerator::class)->shouldReceive('generate')->once()
            ->with($input['text'])->andReturn('「星[ほし]のパン屋[や]77」を見[み]ました。');
        $job = $this->job();
        $job->handle(app(ProcessJournalReading::class));
        $job->handle(app(ProcessJournalReading::class));
        $job->failed(new RuntimeException('late duplicate failure'));
        $this->getJson('/api/journal/entries/'.$entry->id.'/analysis')->assertOk()
            ->assertJsonPath('data.correctedDocument.reading', '「星[ほし]のパン屋[や]77」を見[み]ました。')
            ->assertJsonPath('data.correctedDocument.readingStatus', 'ready')
            ->assertJsonPath('data.replyRevision', 1);
        $this->assertSame($entry->body, $entry->fresh()->body);
        $this->assertSame($analysis->result['correctedText'], $analysis->fresh()->result['correctedText']);
    }

    public function test_unchanged_save_does_not_enqueue_or_increment_revision(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $this->putJson($this->url($entry), [...$input, 'text' => $analysis->result['correctedText']])->assertOk()
            ->assertJsonPath('data.replyRevision', 0)->assertJsonPath('data.correctedDocument.readingStatus', 'ready');
        Queue::assertNothingPushed();
        $this->assertSame($analysis->result, $analysis->fresh()->result);
    }

    public function test_another_device_edit_during_reading_generation_is_not_overwritten(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $this->putJson($this->url($entry), $input)->assertOk();
        $this->mock(StudyCaptureReadingGenerator::class)->shouldReceive('generate')->once()->andReturnUsing(function () use ($entry, $input) {
            $this->putJson($this->url($entry), [...$input, 'expectedRevision' => 1, 'text' => 'はい。'])->assertOk();

            return '「星[ほし]のパン屋[や]77」を見[み]ました。';
        });
        $this->job()->handle(app(ProcessJournalReading::class));
        $this->getJson('/api/journal/entries/'.$entry->id.'/analysis')->assertOk()
            ->assertJsonPath('data.correctedDocument.text', 'はい。')
            ->assertJsonPath('data.correctedDocument.reading', 'はい。')
            ->assertJsonPath('data.correctedDocument.readingStatus', 'ready')
            ->assertJsonPath('data.replyRevision', 2);
    }

    public function test_exhausted_jobs_keep_saved_text_and_can_retry_without_a_new_edit_revision(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $this->putJson($this->url($entry), $input)->assertOk();
        $job = $this->job();
        $job->failed(new RuntimeException('private provider details'));
        $failed = $analysis->fresh()->result;
        $job->failed(new RuntimeException('duplicate failure'));
        $this->assertSame($failed, $analysis->fresh()->result);
        $this->getJson('/api/journal/entries/'.$entry->id.'/analysis')->assertOk()
            ->assertJsonPath('data.correctedDocument.text', $input['text'])
            ->assertJsonPath('data.correctedDocument.readingStatus', 'failed')
            ->assertDontSee('private provider details');
        $this->putJson($this->url($entry), [...$input, 'expectedRevision' => 1])->assertOk()
            ->assertJsonPath('data.correctedDocument.readingStatus', 'pending')
            ->assertJsonPath('data.replyRevision', 1);
        Queue::assertPushed(GenerateJournalReading::class, 2);
        $job->failed(new RuntimeException('old attempt'));
        $this->mock(StudyCaptureReadingGenerator::class)->shouldReceive('generate')->once()
            ->andReturn('「星[ほし]のパン屋[や]77」を見[み]ました。');
        $this->job(1)->handle(app(ProcessJournalReading::class));
        $this->getJson('/api/journal/entries/'.$entry->id.'/analysis')
            ->assertJsonPath('data.correctedDocument.readingStatus', 'ready');
    }

    public function test_queue_outage_does_not_fail_the_save_and_marks_readings_retryable(): void
    {
        [$entry, , $input] = $this->ready();
        Bus::partialMock()->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));
        $this->putJson($this->url($entry), $input)->assertOk()
            ->assertJsonPath('data.correctedDocument.text', $input['text'])
            ->assertJsonPath('data.correctedDocument.readingStatus', 'failed');
    }

    public function test_invalid_stale_and_other_user_edits_never_enqueue(): void
    {
        [$entry, , $input] = $this->ready();
        $this->putJson($this->url($entry), [...$input, 'text' => ' '])->assertUnprocessable();
        $this->putJson($this->url($entry), [...$input, 'text' => str_repeat('あ', 30001)])->assertUnprocessable();
        $this->putJson($this->url($entry), [...$input, 'expectedRevision' => 1])->assertConflict();
        $this->signIn();
        $this->putJson($this->url($entry), $input)->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_kana_only_edits_preserve_whitespace_and_need_no_background_work(): void
    {
        [$entry, , $input] = $this->ready();
        $input['text'] = "\n はい。\n";
        $this->putJson($this->url($entry), $input)->assertOk()
            ->assertJsonPath('data.correctedDocument.text', $input['text'])
            ->assertJsonPath('data.correctedDocument.readingStatus', 'ready');
        $this->putJson($this->url($entry), $input)->assertConflict();
        $this->getJson('/api/journal/entries/'.$entry->id.'/analysis')->assertOk()
            ->assertJsonPath('data.correctedDocument.text', $input['text'])->assertJsonPath('data.replyRevision', 1);
        Queue::assertNothingPushed();
    }

    public function test_deleted_entries_do_not_generate_or_reappear_when_jobs_finish(): void
    {
        [$entry, , $input] = $this->ready();
        $this->putJson($this->url($entry), $input)->assertOk();
        $entry->delete();
        $this->mock(StudyCaptureReadingGenerator::class)->shouldNotReceive('generate');
        $this->job()->handle(app(ProcessJournalReading::class));
        $this->job()->failed(new RuntimeException('deleted'));
        $this->assertNull(JournalEntry::find($entry->id));
        $this->assertDatabaseCount('journal_analyses', 1);
    }

    public function test_superseded_jobs_skip_the_provider_and_old_manual_readings_are_pruned(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $this->putJson($this->url($entry), $input)->assertOk();
        $this->putJson($this->url($entry), [...$input, 'expectedRevision' => 1, 'text' => 'はい。'])->assertOk();
        $this->mock(StudyCaptureReadingGenerator::class)->shouldNotReceive('generate');
        $this->job()->handle(app(ProcessJournalReading::class));
        $this->assertSame([], $analysis->fresh()->result['documentReadings']);
    }

    public function test_a_transient_provider_failure_can_retry_without_losing_text(): void
    {
        [$entry, , $input] = $this->ready();
        $this->putJson($this->url($entry), $input)->assertOk();
        $this->mock(StudyCaptureReadingGenerator::class)->shouldReceive('generate')->once()->andThrow(new RuntimeException('temporary'));
        try {
            $this->job()->handle(app(ProcessJournalReading::class));
            $this->fail('The worker must receive the failure so it can retry.');
        } catch (RuntimeException $error) {
            $this->assertSame('temporary', $error->getMessage());
        }
        $this->getJson('/api/journal/entries/'.$entry->id.'/analysis')
            ->assertJsonPath('data.correctedDocument.text', $input['text'])
            ->assertJsonPath('data.correctedDocument.readingStatus', 'pending');
        $this->mock(StudyCaptureReadingGenerator::class)->shouldReceive('generate')->once()
            ->andReturn('「星[ほし]のパン屋[や]77」を見[み]ました。');
        $this->job()->handle(app(ProcessJournalReading::class));
        $this->getJson('/api/journal/entries/'.$entry->id.'/analysis')
            ->assertJsonPath('data.correctedDocument.readingStatus', 'ready');
    }

    public function test_rolled_back_saves_do_not_enqueue_readings(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        DB::beginTransaction();
        try {
            $this->putJson($this->url($entry), $input)->assertOk();
            Queue::assertNothingPushed();
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, $analysis->fresh()->reply_revision);
        Queue::assertNothingPushed();
    }

    public function test_sync_configuration_still_queues_readings_without_calling_the_provider(): void
    {
        $queue = Queue::getFacadeRoot();
        [$entry, , $input] = $this->ready();
        Queue::swap($queue);
        config(['queue.default' => 'sync']);
        $this->mock(StudyCaptureReadingGenerator::class)->shouldNotReceive('generate');
        $this->putJson($this->url($entry), $input)->assertOk()
            ->assertJsonPath('data.correctedDocument.readingStatus', 'pending');
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_background_readings_survive_reply_snapshots_and_undo(): void
    {
        [$entry, , $input] = $this->ready();
        $url = '/api/journal/entries/'.$entry->id;
        $this->putJson($this->url($entry), $input)->assertOk();
        $replyId = strtolower((string) Str::ulid());
        $this->putJson($url.'/replies/'.$replyId, ['analysisId' => $input['analysisId'],
            'answer' => 'はい。', 'expectedRevision' => 1, 'replace' => false])->assertAccepted();
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->andReturn(JournalFeedbackFixture::result());
        (new AnalyzeJournalReply($replyId))->handle(app(ProcessJournalReply::class));
        $this->mock(StudyCaptureReadingGenerator::class)->shouldReceive('generate')->once()
            ->andReturn('「星[ほし]のパン屋[や]77」を見[み]ました。');
        $this->job()->handle(app(ProcessJournalReading::class));
        $this->postJson($url.'/undo', ['analysisId' => $input['analysisId'], 'expectedRevision' => 2])->assertOk()
            ->assertJsonPath('data.correctedDocument.text', $input['text'])
            ->assertJsonPath('data.correctedDocument.reading', '「星[ほし]のパン屋[や]77」を見[み]ました。')
            ->assertJsonPath('data.correctedDocument.readingStatus', 'ready');
    }

    private function ready(): array
    {
        Queue::fake();
        $entry = JournalEntry::factory()->for($this->signIn())->create();
        $analysis = JournalAnalysis::factory()->create([
            'entry_id' => $entry->id, 'source_version' => 1, 'source_body' => $entry->body,
            'status' => JournalAnalysisStatus::Ready, 'result' => JournalFeedbackFixture::result(),
        ]);

        return [$entry, $analysis, ['analysisId' => strtoupper($analysis->id), 'expectedRevision' => 0,
            'text' => '「星のパン屋77」を見ました。']];
    }

    private function job(int $index = 0): GenerateJournalReading
    {
        return Queue::pushed(GenerateJournalReading::class)[$index];
    }

    private function url(JournalEntry $entry): string
    {
        return '/api/journal/entries/'.$entry->id.'/corrected';
    }
}
