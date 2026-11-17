<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\ProcessJournalAnalysis;
use App\Domain\Journal\Actions\ProcessJournalClarification;
use App\Domain\Journal\Actions\StartJournalAnalysis;
use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Services\GenerateJournalFeedback;
use App\Domain\Journal\Support\JournalPreparedFeedback;
use App\Jobs\AnalyzeJournalEntry;
use App\Jobs\ClarifyJournalEntry;
use App\Jobs\RenderJournalQuestionAudio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Journal\CompactFeedbackFixture;
use Tests\Unit\Journal\JournalCompactFeedbackTest;
use Tests\Unit\Journal\JournalFeedbackFixture;

final class JournalCombinedFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolved_meaning_produces_feedback_once_and_submission_reuses_it(): void
    {
        Bus::fake([RenderJournalQuestionAudio::class]);
        config(['services.openai.api_key' => 'test']);
        $entry = JournalEntry::factory()->create(['body' => '映画を見ますた。']);
        $state = JournalClarification::factory()->create(['entry_id' => $entry->id, 'source_body' => $entry->body]);
        Http::fakeSequence()->push(JournalCompactFeedbackTest::response([
            'resolved' => true, 'question' => null, 'confirmedMeaning' => '', 'feedback' => CompactFeedbackFixture::result(),
        ]));
        $clarify = new ClarifyJournalEntry($state->id, 0);
        $clarify->handle(app(ProcessJournalClarification::class));
        $clarify->handle(app(ProcessJournalClarification::class));
        $this->assertDatabaseCount('journal_analyses', 0);
        $this->assertNotNull($state->fresh()->prepared_feedback);
        $analysis = app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1, $state->id);
        $job = new AnalyzeJournalEntry($analysis->id);
        $job->handle(app(ProcessJournalAnalysis::class));
        $job->handle(app(ProcessJournalAnalysis::class));
        Http::assertSentCount(1);
        $this->assertSame('ready', $analysis->fresh()->status->value);
        $this->assertSame('映画を見ました。', $analysis->fresh()->result['correctedText']);
        $this->assertDatabaseCount('ai_usage_events', 1);
        $this->assertSame('映画', $entry->fresh()->title);
        $this->assertFalse($state->isFillable('prepared_feedback'));
        $this->assertFalse($state->isFillable('prepared_context_hash'));
    }

    public function test_changed_conversation_context_falls_back_to_fresh_feedback(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '映画を見ますた。']);
        $state = JournalClarification::factory()->create(['entry_id' => $entry->id, 'source_body' => $entry->body,
            'status' => 'resolved', 'confirmed_meaning' => '', 'prepared_feedback' => JournalFeedbackFixture::result(),
            'prepared_context_hash' => JournalPreparedFeedback::contextHash(['question' => 'old context'])]);
        $analysis = app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1, $state->id);
        $this->mock(GenerateJournalFeedback::class)->shouldReceive('handle')->once()->andReturn(JournalFeedbackFixture::result());
        (new AnalyzeJournalEntry($analysis->id))->handle(app(ProcessJournalAnalysis::class));
        $this->assertSame('ready', $analysis->fresh()->status->value);
    }

    public function test_edit_after_preparation_prevents_cached_feedback_from_being_published(): void
    {
        Http::fake();
        $entry = JournalEntry::factory()->create(['body' => '映画を見ますた。']);
        $state = JournalClarification::factory()->create(['entry_id' => $entry->id, 'source_body' => $entry->body,
            'status' => 'resolved', 'confirmed_meaning' => '', 'prepared_feedback' => JournalFeedbackFixture::result(),
            'prepared_context_hash' => JournalPreparedFeedback::contextHash([])]);
        $analysis = app(StartJournalAnalysis::class)->handle($entry->user_id, $entry->id, 1, $state->id);
        $entry->forceFill(['body' => '違う文章。', 'version' => 2])->save();
        (new AnalyzeJournalEntry($analysis->id))->handle(app(ProcessJournalAnalysis::class));
        $this->assertSame('failed', $analysis->fresh()->status->value);
        $this->assertNull($analysis->fresh()->result);
        Http::assertNothingSent();
    }

    public function test_edit_during_combined_generation_discards_prepared_feedback(): void
    {
        config(['services.openai.api_key' => 'test']);
        $entry = JournalEntry::factory()->create(['body' => '映画を見ますた。']);
        $state = JournalClarification::factory()->create(['entry_id' => $entry->id, 'source_body' => $entry->body]);
        Http::fake(function () use ($entry) {
            $entry->forceFill(['version' => 2, 'body' => '違う文章。'])->save();

            return Http::response(JournalCompactFeedbackTest::response([
                'resolved' => true, 'question' => null, 'confirmedMeaning' => '', 'feedback' => CompactFeedbackFixture::result(),
            ]));
        });
        (new ClarifyJournalEntry($state->id, 0))->handle(app(ProcessJournalClarification::class));
        $this->assertSame('failed', $state->fresh()->status->value);
        $this->assertNull($state->fresh()->prepared_feedback);
        $this->assertNull($entry->fresh()->title);
        $this->assertDatabaseCount('journal_analyses', 0);
    }
}
