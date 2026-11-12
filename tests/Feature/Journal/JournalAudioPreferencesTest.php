<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\GenerateJournalAudio;
use App\Domain\Journal\Enums\JournalAnalysisStatus;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalAudioState;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class JournalAudioPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private const HARU = 'fishaudio:21c74cb823fa45ef996ccc91cd6038b6';

    private const YUA = 'fishaudio:030fda5bec284b038ad930effd150f94';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.fish_audio.api_key' => 'test', 'services.fish_audio.base_url' => 'https://fish.test']);
        Http::fake(['fish.test/*' => Http::response('ID3audio')]);
    }

    public function test_generation_remembers_each_entry_and_sets_the_default_but_cached_playback_does_not(): void
    {
        $user = $this->signIn();
        [$first, $firstAnalysis] = $this->entry($user);
        [$second, $secondAnalysis] = $this->entry($user);
        [$new, $newAnalysis] = $this->entry($user);
        $this->state($first, $firstAnalysis)->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => ['voiceId' => self::HARU, 'hasAudio' => false]]);
        $version = $first->version;
        $updatedAt = $first->updated_at->toISOString();
        $this->generate($first, $firstAnalysis, self::HARU)->assertOk();
        $this->generate($second, $secondAnalysis, self::YUA)->assertOk();
        $this->generate($first, $firstAnalysis, self::HARU)->assertOk();
        $this->state($first, $firstAnalysis)->assertExactJson(['data' => ['voiceId' => self::HARU, 'hasAudio' => true]]);
        $this->state($second, $secondAnalysis)->assertJsonPath('data.voiceId', self::YUA);
        $this->state($new, $newAnalysis)->assertExactJson(['data' => ['voiceId' => self::YUA, 'hasAudio' => false]]);
        $this->assertDatabaseHas('journal_audio_preferences', ['user_id' => $user->id, 'voice_id' => self::YUA]);
        $this->assertDatabaseHas('journal_entries', ['id' => $first->id, 'audio_voice_id' => self::HARU, 'audio_text_hash' => hash('sha256', '映画を見ました。')]);
        $this->assertSame($version, $first->fresh()->version);
        $this->assertSame($updatedAt, $first->fresh()->updated_at->toISOString());
        Http::assertSentCount(2);
        $second->delete();
        $this->state($new, $newAnalysis)->assertJsonPath('data.voiceId', self::YUA);
    }

    public function test_replaying_an_older_voice_does_not_replace_the_entrys_last_generated_voice(): void
    {
        [$entry, $analysis] = $this->entry($this->signIn());
        $this->generate($entry, $analysis, self::HARU)->assertOk();
        $this->generate($entry, $analysis, self::YUA)->assertOk();
        $this->generate($entry, $analysis, self::HARU)->assertOk();
        $this->state($entry, $analysis)->assertJsonPath('data.voiceId', self::YUA);
        Http::assertSentCount(2);
    }

    public function test_legacy_cached_audio_is_selected_without_changing_the_default_or_generating(): void
    {
        $user = $this->signIn();
        [$entry, $analysis] = $this->entry($user);
        Storage::disk('local')->put(JournalAudioState::path($entry, self::YUA, '映画を見ました。'), 'ID3legacy');
        $this->state($entry, $analysis)->assertExactJson(['data' => ['voiceId' => self::YUA, 'hasAudio' => true]]);
        $this->generate($entry, $analysis, self::YUA)->assertOk();
        $this->assertDatabaseCount('journal_audio_preferences', 0);
        Http::assertNothingSent();
    }

    public function test_a_missing_recording_uses_the_account_default_instead_of_the_old_entry_voice(): void
    {
        $user = $this->signIn();
        [$entry, $analysis] = $this->entry($user);
        [$other, $otherAnalysis] = $this->entry($user);
        $this->generate($entry, $analysis, self::HARU)->assertOk();
        $this->generate($other, $otherAnalysis, self::YUA)->assertOk();
        Storage::disk('local')->deleteDirectory('journal/'.$entry->id);
        $this->state($entry, $analysis)->assertExactJson(['data' => ['voiceId' => self::YUA, 'hasAudio' => false]]);
        $this->assertSame(self::HARU, $entry->fresh()->audio_voice_id);
        Http::assertSentCount(2);
    }

    public function test_restoring_older_corrected_text_selects_its_cached_voice_despite_newer_metadata(): void
    {
        [$entry, $analysis] = $this->entry($this->signIn());
        $this->generate($entry, $analysis, self::HARU)->assertOk();
        $original = $analysis->result;
        $analysis->result = ['correctedText' => '映画の後で話しました。'];
        $analysis->save();
        $this->generate($entry, $analysis, self::YUA)->assertOk();
        $analysis->result = $original;
        $analysis->save();
        $this->state($entry, $analysis)->assertExactJson(['data' => ['voiceId' => self::HARU, 'hasAudio' => true]]);
        $this->assertSame(self::YUA, $entry->fresh()->audio_voice_id);
        $this->assertDatabaseHas('journal_audio_preferences', ['user_id' => $entry->user_id, 'voice_id' => self::YUA]);
        Http::assertSentCount(2);
    }

    public function test_state_is_private_and_rejects_stale_or_malformed_versions(): void
    {
        [$entry, $analysis] = $this->entry($this->signIn());
        $this->generate($entry, $analysis, self::YUA)->assertOk();
        $analysis->reply_revision = 1;
        $analysis->result = ['correctedText' => '映画の後で話しました。'];
        $analysis->save();
        $this->state($entry, $analysis)->assertConflict();
        $url = '/api/journal/entries/'.$entry->id.'/audio';
        $this->getJson($url.'?'.http_build_query(['analysisId' => strtoupper($analysis->id), 'expectedReplyRevision' => '+1']))
            ->assertExactJson(['data' => ['voiceId' => self::YUA, 'hasAudio' => false]]);
        $this->getJson($url.'?analysisId[]=bad')->assertUnprocessable();
        $other = $this->signIn();
        $this->state($entry, $analysis)->assertNotFound();
        [$otherEntry, $otherAnalysis] = $this->entry($other);
        $this->state($otherEntry, $otherAnalysis)->assertJsonPath('data.voiceId', self::HARU);
        Http::assertSentCount(1);
    }

    public function test_provider_failures_do_not_change_preferences_and_account_deletion_cleans_them_up(): void
    {
        [$entry, $analysis] = $this->entry($this->signIn());
        $this->generate($entry, $analysis, self::HARU)->assertOk();
        Http::swap(new Factory);
        Http::fake(['fish.test/*' => Http::response('', 500)]);
        $this->generate($entry, $analysis, self::YUA)->assertStatus(503);
        $this->assertDatabaseHas('journal_audio_preferences', ['user_id' => $entry->user_id, 'voice_id' => self::HARU]);
        $this->assertSame(self::HARU, $entry->fresh()->audio_voice_id);
        $entry->user->delete();
        $this->assertDatabaseCount('journal_audio_preferences', 0);
    }

    public function test_preference_write_failure_rolls_back_metadata_and_removes_the_new_cache_file(): void
    {
        [$entry, $analysis] = $this->entry($this->signIn());
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into "journal_audio_preferences"')) {
                throw new RuntimeException('Simulated preference write failure');
            }
        });
        try {
            app(GenerateJournalAudio::class)->handle($entry, ['analysisId' => $analysis->id, 'voiceId' => self::HARU]);
            $this->fail('A failed preference write must fail publication.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated preference write failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('journal_audio_preferences', 0);
        $this->assertNull($entry->fresh()->audio_voice_id);
        $this->assertNull($entry->fresh()->audio_text_hash);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_answer_changing_during_synthesis_does_not_publish_audio_or_preferences(): void
    {
        [$entry, $analysis] = $this->entry($this->signIn());
        Http::fake(function () use ($analysis) {
            $analysis->increment('reply_revision');

            return Http::response('ID3audio');
        });
        $this->generate($entry, $analysis, self::HARU)->assertConflict();
        $this->assertDatabaseCount('journal_audio_preferences', 0);
        $this->assertNull($entry->fresh()->audio_voice_id);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function entry($user): array
    {
        $entry = JournalEntry::factory()->for($user)->create(['body' => '映画を見ますた。']);
        $analysis = JournalAnalysis::factory()->create([
            'entry_id' => $entry->id, 'source_body' => $entry->body,
            'status' => JournalAnalysisStatus::Ready, 'result' => ['correctedText' => '映画を見ました。'],
        ]);

        return [$entry, $analysis];
    }

    private function state(JournalEntry $entry, JournalAnalysis $analysis)
    {
        return $this->getJson('/api/journal/entries/'.$entry->id.'/audio?analysisId='.$analysis->id);
    }

    private function generate(JournalEntry $entry, JournalAnalysis $analysis, string $voice)
    {
        return $this->postJson('/api/journal/entries/'.$entry->id.'/audio', ['analysisId' => $analysis->id, 'voiceId' => $voice]);
    }
}
