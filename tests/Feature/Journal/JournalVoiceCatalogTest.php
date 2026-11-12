<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class JournalVoiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_exposes_only_the_six_selected_voices_with_english_names_and_gender(): void
    {
        $this->signIn();
        $voices = $this->getJson('/api/journal/voices')->assertOk()->assertJsonCount(6, 'data')->json('data');
        $this->assertSame(['Haru (M)', 'Kaito (M)', 'Daichi (M)', 'Aiko (F)', 'Yua (F)', 'Hina (F)'], array_column($voices, 'name'));
        $this->assertSame([
            'fishaudio:21c74cb823fa45ef996ccc91cd6038b6', 'fishaudio:434dd33d4d3c4cf68b98e3d7f25f31ab',
            'fishaudio:9bf6608381a34f47ada3753f2e9f9381', 'fishaudio:35c8e5ae5239435f8d9c26c86802b86d',
            'fishaudio:030fda5bec284b038ad930effd150f94', 'fishaudio:63bc41e652214372b15d9416a30a60b4',
        ], array_column($voices, 'id'));
        foreach (config('journal_voices') as $id => $voice) {
            $this->assertSame(config('reading_voices')[$id], $voice['provider_name']);
        }
    }

    public function test_removed_voices_cannot_trigger_journal_synthesis(): void
    {
        Http::fake();
        $entry = JournalEntry::factory()->for($this->signIn())->create();
        $this->postJson('/api/journal/entries/'.$entry->id.'/audio', [
            'analysisId' => strtolower((string) Str::ulid()),
            'voiceId' => 'fishaudio:abb4362e736f40b7b5716f4fafcafa9f',
        ])->assertUnprocessable()->assertJsonValidationErrors('voiceId');
        Http::assertNothingSent();
    }
}
