<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Study\Exceptions\StudyCaptureReadingException;
use App\Domain\Study\Services\StudyCaptureReadingGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\Journal\JournalFeedbackFixture;

class JournalManualEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_corrected_text_can_be_edited_and_readings_are_generated_without_rewriting(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $this->mock(StudyCaptureReadingGenerator::class)->shouldReceive('generate')->once()
            ->with('「星のパン屋77」を見ました。')->andReturn('「星[ほし]のパン屋[や]77」を見[み]ました。');
        $this->putJson($this->url($entry), $input)->assertOk()
            ->assertJsonPath('data.correctedDocument.text', $input['text'])
            ->assertJsonPath('data.correctedDocument.reading', '「星[ほし]のパン屋[や]77」を見[み]ました。')
            ->assertJsonPath('data.replyRevision', 1);
        $this->assertSame($entry->body, $entry->fresh()->body);
        $this->assertSame($analysis->result['correctedText'], $analysis->fresh()->result['correctedText']);
    }

    public function test_unchanged_save_does_not_generate_readings_or_increment_revision(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $this->mock(StudyCaptureReadingGenerator::class)->shouldNotReceive('generate');
        $this->putJson($this->url($entry), [...$input, 'text' => $analysis->result['correctedText']])->assertOk()
            ->assertJsonPath('data.replyRevision', 0);
    }

    public function test_another_device_edit_during_reading_generation_is_not_overwritten(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $this->mock(StudyCaptureReadingGenerator::class)->shouldReceive('generate')->once()->andReturnUsing(function () use ($analysis) {
            $analysis->reply_revision = 1;
            $analysis->save();

            return '「星[ほし]のパン屋[や]77」を見[み]ました。';
        });
        $this->putJson($this->url($entry), $input)->assertConflict();
        $this->assertArrayNotHasKey('document', $analysis->fresh()->result);
    }

    public function test_reading_failures_leave_the_saved_entry_untouched(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $this->mock(StudyCaptureReadingGenerator::class)->shouldReceive('generate')->once()
            ->andThrow(new StudyCaptureReadingException(new RuntimeException('provider details')));
        $this->putJson($this->url($entry), $input)->assertUnprocessable()
            ->assertJsonValidationErrors('text')->assertDontSee('provider details');
        $this->assertSame(0, $analysis->fresh()->reply_revision);
        $this->assertArrayNotHasKey('document', $analysis->fresh()->result);
    }

    public function test_invalid_stale_and_other_user_edits_never_call_the_provider(): void
    {
        [$entry, , $input] = $this->ready();
        $this->mock(StudyCaptureReadingGenerator::class)->shouldNotReceive('generate');
        $this->putJson($this->url($entry), [...$input, 'text' => ' '])->assertUnprocessable();
        $this->putJson($this->url($entry), [...$input, 'text' => str_repeat('あ', 30001)])->assertUnprocessable();
        $this->putJson($this->url($entry), [...$input, 'expectedRevision' => 1])->assertConflict();
        $this->signIn();
        $this->putJson($this->url($entry), $input)->assertNotFound();
    }

    public function test_http_preserves_surrounding_whitespace_and_a_lost_response_requires_refetch(): void
    {
        [$entry, $analysis, $input] = $this->ready();
        $input['text'] = "\n はい。\n";
        $this->mock(StudyCaptureReadingGenerator::class)->shouldReceive('generate')->once()
            ->with($input['text'])->andReturn($input['text']);
        $this->putJson($this->url($entry), $input)->assertOk()->assertJsonPath('data.correctedDocument.text', $input['text']);
        $this->putJson($this->url($entry), $input)->assertConflict();
        $this->getJson('/api/journal/entries/'.$entry->id.'/analysis')->assertOk()
            ->assertJsonPath('data.correctedDocument.text', $input['text'])->assertJsonPath('data.replyRevision', 1);
    }

    private function ready(): array
    {
        $entry = JournalEntry::factory()->for($this->signIn())->create();
        $analysis = new JournalAnalysis;
        $analysis->entry_id = $entry->id;
        $analysis->source_version = 1;
        $analysis->source_body = $entry->body;
        $analysis->status = JournalAnalysisStatus::Ready;
        $analysis->result = JournalFeedbackFixture::result();
        $analysis->save();

        return [$entry, $analysis, ['analysisId' => strtoupper($analysis->id), 'expectedRevision' => 0,
            'text' => '「星のパン屋77」を見ました。']];
    }

    private function url(JournalEntry $entry): string
    {
        return '/api/journal/entries/'.$entry->id.'/corrected';
    }
}
