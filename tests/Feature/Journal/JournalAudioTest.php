<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JournalAudioTest extends TestCase
{
    use RefreshDatabase;

    private const VOICE = 'fishaudio:abb4362e736f40b7b5716f4fafcafa9f';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.fish_audio.api_key' => 'test', 'services.fish_audio.base_url' => 'https://fish.test']);
    }

    public function test_corrected_audio_is_private_and_reused_for_the_same_voice_and_text(): void
    {
        [$entry, $analysis] = $this->readyEntry();
        $input = ['analysisId' => strtoupper($analysis->id), 'voiceId' => self::VOICE];
        $url = '/api/journal/entries/'.$entry->id.'/audio';
        $this->postJson($url, $input)->assertOk()->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('Cache-Control', 'no-store, private')->assertStreamedContent('ID3test-audio');
        $this->postJson($url, $input)->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['text'] === '映画を見ました。'
            && $request['reference_id'] === substr(self::VOICE, 10));
        $this->getJson('/api/journal/voices')->assertOk()->assertJsonFragment(['id' => self::VOICE, 'name' => 'Ren']);
    }

    public function test_another_voice_uses_a_different_cached_audio_file(): void
    {
        [$entry, $analysis] = $this->readyEntry();
        $url = '/api/journal/entries/'.$entry->id.'/audio';
        foreach ([self::VOICE, 'fishaudio:9639f090aa6346329d7d3aca7e6b7226'] as $voice) {
            $this->postJson($url, ['analysisId' => $analysis->id, 'voiceId' => $voice])->assertOk();
        }
        Http::assertSentCount(2);
        $this->assertCount(2, Storage::disk('local')->allFiles('journal/'.$entry->id));
    }

    public function test_foreign_deleted_and_changed_entries_cannot_play_the_snapshot(): void
    {
        [$entry, $analysis] = $this->readyEntry();
        $input = ['analysisId' => $analysis->id, 'voiceId' => self::VOICE];
        $url = '/api/journal/entries/'.$entry->id.'/audio';
        $entry->body = '別の日。';
        $entry->save();
        $this->postJson($url, $input)->assertConflict();
        $this->signIn();
        $this->postJson($url, $input)->assertNotFound();
        $entry->delete();
        $this->postJson($url, $input)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_voice_input_and_analysis_identity_are_checked(): void
    {
        [$entry, $analysis] = $this->readyEntry();
        $url = '/api/journal/entries/'.$entry->id.'/audio';
        $this->postJson($url, ['analysisId' => $analysis->id, 'voiceId' => 'unknown'])->assertUnprocessable();
        $this->postJson($url, ['analysisId' => 'invalid', 'voiceId' => self::VOICE])->assertUnprocessable();
        $analysis->status = JournalAnalysisStatus::Processing;
        $analysis->save();
        $this->postJson($url, ['analysisId' => $analysis->id, 'voiceId' => self::VOICE])->assertConflict();
        Http::assertNothingSent();
    }

    public function test_provider_errors_do_not_expose_provider_details_or_cache_failed_audio(): void
    {
        [$entry, $analysis] = $this->readyEntry(500);
        $this->postJson('/api/journal/entries/'.$entry->id.'/audio', ['analysisId' => $analysis->id, 'voiceId' => self::VOICE])
            ->assertStatus(503)->assertJsonPath('message', 'Audio is temporarily unavailable. Please try again.');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_audio_and_voice_catalog_require_authentication(): void
    {
        $entry = JournalEntry::factory()->create();
        $this->getJson('/api/journal/voices')->assertUnauthorized();
        $this->postJson('/api/journal/entries/'.$entry->id.'/audio')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_long_entries_have_an_explicit_audio_limit(): void
    {
        [$entry, $analysis] = $this->readyEntry();
        $analysis->result = ['correctedText' => str_repeat('あ', 15001)];
        $analysis->save();
        $this->postJson('/api/journal/entries/'.$entry->id.'/audio', ['analysisId' => $analysis->id, 'voiceId' => self::VOICE])
            ->assertUnprocessable()->assertJsonValidationErrors('audio');
        Http::assertNothingSent();
    }

    public function test_deleting_an_entry_removes_its_cached_audio_and_retries_are_safe(): void
    {
        [$entry, $analysis] = $this->readyEntry();
        $url = '/api/journal/entries/'.$entry->id;
        $this->postJson($url.'/audio', ['analysisId' => $analysis->id, 'voiceId' => self::VOICE])->assertOk();
        $this->assertNotEmpty(Storage::disk('local')->allFiles());
        $this->deleteJson($url, ['expectedVersion' => 1])->assertNoContent();
        $this->deleteJson($url, ['expectedVersion' => 1])->assertNoContent();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_deletion_during_synthesis_cannot_recreate_private_audio(): void
    {
        [$entry, $analysis] = $this->readyEntry(200, true);
        $this->postJson('/api/journal/entries/'.$entry->id.'/audio', ['analysisId' => $analysis->id, 'voiceId' => self::VOICE])->assertNotFound();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_malformed_ready_feedback_does_not_reach_the_provider(): void
    {
        [$entry, $analysis] = $this->readyEntry();
        $analysis->result = [];
        $analysis->save();
        $this->postJson('/api/journal/entries/'.$entry->id.'/audio', ['analysisId' => $analysis->id, 'voiceId' => self::VOICE])->assertConflict();
        Http::assertNothingSent();
    }

    private function readyEntry(int $providerStatus = 200, bool $deleteDuringSynthesis = false): array
    {
        $user = $this->signIn();
        $entry = JournalEntry::factory()->for($user)->create(['body' => '映画を見ますた。']);
        $analysis = JournalAnalysis::factory()->create([
            'entry_id' => $entry->id, 'source_body' => $entry->body,
            'status' => JournalAnalysisStatus::Ready, 'result' => ['correctedText' => '映画を見ました。'],
        ]);

        Http::fake(['fish.test/*' => function () use ($entry, $providerStatus, $deleteDuringSynthesis) {
            if ($deleteDuringSynthesis) {
                $entry->delete();
            }

            return Http::response('ID3test-audio', $providerStatus);
        }]);

        return [$entry, $analysis];
    }
}
