<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class JournalTimedAudioTest extends TestCase
{
    use RefreshDatabase;

    private const VOICE = 'fishaudio:21c74cb823fa45ef996ccc91cd6038b6';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.fish_audio.api_key' => 'test', 'services.fish_audio.base_url' => 'https://fish.test']);
    }

    public function test_timed_audio_returns_matching_sentences_and_reuses_private_cache(): void
    {
        [$entry, $input] = $this->readyEntry();
        Http::fake(['fish.test/*' => Http::response($this->stream())]);
        $url = '/api/journal/entries/'.$entry->id.'/audio/timed';
        $this->postJson($url, $input)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.audioBase64', base64_encode('ID3test'))
            ->assertJsonPath('data.sentences.0.text', '映画を見た。')->assertJsonPath('data.sentences.1.start', 2);
        $this->postJson($url, $input)->assertOk();
        Http::assertSentCount(1);
        $this->getJson('/api/journal/entries/'.$entry->id.'/audio?'.http_build_query($input))->assertOk()->assertJsonPath('data.hasAudio', true);
        $this->deleteJson('/api/journal/entries/'.$entry->id, ['expectedVersion' => 1])->assertNoContent();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function invalidations(): array
    {
        return [['revision', 409], ['entry', 404], ['account', 404]];
    }

    #[DataProvider('invalidations')]
    public function test_invalidated_source_during_synthesis_cannot_publish(string $change, int $status): void
    {
        [$entry, $input] = $this->readyEntry();
        Http::fake(function () use ($entry, $input, $change) {
            match ($change) {
                'revision' => JournalAnalysis::findOrFail($input['analysisId'])->increment('reply_revision'),
                'entry' => $entry->delete(),
                'account' => $entry->user->delete(),
            };

            return Http::response($this->stream());
        });
        $this->postJson('/api/journal/entries/'.$entry->id.'/audio/timed', $input)->assertStatus($status);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_foreign_entries_cannot_generate_audio(): void
    {
        [$entry, $input] = $this->readyEntry();
        $this->signIn();
        $this->postJson('/api/journal/entries/'.$entry->id.'/audio/timed', $input)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_question_audio_uses_the_question_snapshot_and_rejects_foreign_ids(): void
    {
        [$entry, $input] = $this->readyEntry();
        $question = new JournalQuestion;
        $question->source_key = 'analysis';
        $question->analysis_id = $input['analysisId'];
        $question->text = '映画を見た。楽しかった。';
        $question->reading = $question->text;
        $question->translation = 'A movie.';
        $question->tutor = ['voiceId' => self::VOICE];
        $question->save();
        Http::fake(['fish.test/*' => Http::response($this->stream())]);
        $url = '/api/journal/entries/'.$entry->id.'/questions/'.$question->id.'/audio/timed';
        $this->postJson($url)->assertOk()->assertJsonCount(2, 'data.sentences');
        $this->postJson($url)->assertOk();
        Http::assertSentCount(1);
        $other = JournalEntry::factory()->for($entry->user)->create();
        $this->postJson('/api/journal/entries/'.$other->id.'/questions/'.$question->id.'/audio/timed')->assertNotFound();
    }

    public function test_corrupt_cache_is_replaced_on_retry(): void
    {
        [$entry, $input] = $this->readyEntry();
        Http::fake(['fish.test/*' => Http::response($this->stream())]);
        $url = '/api/journal/entries/'.$entry->id.'/audio/timed';
        $this->postJson($url, $input)->assertOk();
        $path = Storage::disk('local')->allFiles()[0];
        Storage::disk('local')->put($path, '{incomplete');
        $this->postJson($url, $input)->assertOk()->assertJsonCount(2, 'data.sentences');
        Http::assertSentCount(2);
    }

    public function test_provider_failures_are_retryable_and_generation_is_rate_limited(): void
    {
        [$entry, $input] = $this->readyEntry();
        Http::fake(['fish.test/*' => Http::response('provider details', 500)]);
        $url = '/api/journal/entries/'.$entry->id.'/audio/timed';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson($url, $input)->assertStatus(503)->assertJsonPath('message', 'Audio is temporarily unavailable. Please try again.');
        }
        $this->postJson($url, $input)->assertStatus(429);
        Http::assertSentCount(5);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_missing_alignment_is_not_permanently_cached(): void
    {
        [$entry, $input] = $this->readyEntry();
        Http::fake(['fish.test/*' => Http::sequence()
            ->push('data: '.json_encode(['audio_base64' => base64_encode('ID3test'), 'alignment' => null]))
            ->push($this->stream())]);
        $url = '/api/journal/entries/'.$entry->id.'/audio/timed';
        $this->postJson($url, $input)->assertOk()->assertJsonCount(0, 'data.sentences');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->postJson($url, $input)->assertOk()->assertJsonCount(2, 'data.sentences');
        Http::assertSentCount(2);
    }

    private function readyEntry(): array
    {
        $user = $this->signIn();
        $entry = JournalEntry::factory()->for($user)->create();
        $analysis = JournalAnalysis::factory()->create([
            'entry_id' => $entry->id, 'source_body' => $entry->body,
            'status' => JournalAnalysisStatus::Ready, 'result' => ['correctedText' => '映画を見た。楽しかった。'],
        ]);

        return [$entry, ['analysisId' => $analysis->id, 'voiceId' => self::VOICE]];
    }

    private function stream(): string
    {
        return 'data: '.json_encode([
            'audio_base64' => base64_encode('ID3test'), 'chunk_seq' => 0, 'chunk_audio_offset_sec' => 0,
            'alignment' => ['segments' => [
                ['text' => '映画を見た。', 'start' => 0, 'end' => 2],
                ['text' => '楽しかった。', 'start' => 2, 'end' => 4],
            ]],
        ], JSON_THROW_ON_ERROR)."\n\n";
    }
}
