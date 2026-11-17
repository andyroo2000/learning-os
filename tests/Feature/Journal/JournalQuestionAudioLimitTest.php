<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\GenerateJournalQuestionAudio;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class JournalQuestionAudioLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_sixth_prewarm_is_retryable_without_bypassing_the_shared_synthesis_budget(): void
    {
        Storage::fake('local');
        config(['services.fish_audio.api_key' => 'test']);
        Http::fakeSequence()->pushResponse(Http::response('data: '.json_encode(['audio_base64' => base64_encode('ID3audio')])))->whenEmpty(Http::response('data: '.json_encode(['audio_base64' => base64_encode('ID3audio')])));
        $analysis = JournalAnalysis::factory()->create();
        $action = app(GenerateJournalQuestionAudio::class);
        for ($index = 0; $index < 5; $index++) {
            $question = $this->question($analysis->id, $index);
            $action->handle($question->id, $question->audio_attempt_id);
            $this->assertSame('ready', $question->fresh()->audio_status);
        }
        $sixth = $this->question($analysis->id, 5);
        try {
            $action->handle($sixth->id, $sixth->audio_attempt_id);
            $this->fail('Expected synthesis rate limit');
        } catch (HttpException $error) {
            $this->assertSame(429, $error->getStatusCode());
        }
        $this->assertSame('failed', $sixth->fresh()->audio_status);
        Http::assertSentCount(5);
        RateLimiter::clear('journal-timed:'.$analysis->entry->user_id);
        $sixth->refresh()->forceFill(['audio_status' => 'queued', 'audio_attempt_id' => strtolower((string) Str::ulid())])->save();
        $action->handle($sixth->id, $sixth->audio_attempt_id);
        $this->assertSame('ready', $sixth->fresh()->audio_status);
        Http::assertSentCount(6);
    }

    private function question(string $analysisId, int $index): JournalQuestion
    {
        $question = new JournalQuestion;
        $question->forceFill(['analysis_id' => $analysisId, 'source_key' => 'test-'.$index,
            'text' => 'どうでしたか。'.$index, 'reading' => 'どうでしたか。'.$index, 'translation' => 'How was it?',
            'tutor' => ['voiceId' => 'fishaudio:21c74cb823fa45ef996ccc91cd6038b6'],
            'audio_status' => 'queued', 'audio_attempt_id' => strtolower((string) Str::ulid()), 'audio_requested_at' => now()])->save();

        return $question;
    }
}
