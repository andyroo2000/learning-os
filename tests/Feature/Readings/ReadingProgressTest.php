<?php

namespace Tests\Feature\Readings;

use App\Domain\Readings\Models\Reading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReadingProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_replays_and_delayed_offline_updates_never_replace_newer_position(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('ios')->plainTextToken);
        $reading = $this->reading($user);
        $url = '/api/reading-progress/'.$reading->id;
        $this->getJson($url)->assertOk()->assertJsonPath('position', null);
        $data = $this->position();
        $this->putJson($url, $data)->assertOk()->assertJsonPath('position.pageNumber', 24);
        $this->putJson($url, $data)->assertOk()->assertJsonPath('position.eventId', $data['eventId']);
        $newer = [...$data, 'readAt' => now()->toISOString(), 'eventId' => (string) Str::uuid(), 'sentenceIndex' => 1];
        $this->putJson($url, $newer)->assertOk()->assertJsonPath('position.sentenceIndex', 1);
        $this->putJson($url, $data)->assertOk()->assertJsonPath('position.sentenceIndex', 1);
        $this->getJson('/api/reading-progress')->assertOk()->assertJsonPath('continue.id', $reading->id)
            ->assertJsonPath('continue.position.sentenceIndex', 1);
        $this->assertDatabaseCount('reading_progress', 1);
    }

    public function test_positions_are_private_and_validate_page_sentence_revision_and_clock(): void
    {
        $user = User::factory()->create();
        $reading = $this->reading($user);
        $url = '/api/reading-progress/'.$reading->id;
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
        $this->putJson($url, $this->position())->assertNotFound();
        $this->getJson('/api/reading-progress')->assertOk()->assertJsonPath('continue', null);
        $this->actingAs($user);
        $this->putJson($url, [...$this->position(), 'pageNumber' => 25])->assertUnprocessable();
        $this->putJson($url, [...$this->position(), 'sentenceIndex' => 2])->assertUnprocessable();
        $this->putJson($url, [...$this->position(), 'readingRevision' => 2])->assertConflict();
        $this->putJson($url, [...$this->position(), 'readAt' => now()->addDay()->toISOString()])->assertUnprocessable();
        $this->putJson($url, [...$this->position(), 'sentenceIndex' => null])->assertOk()->assertJsonPath('position.sentenceIndex', null);
        $this->getJson($url.'?viewAs=other')->assertUnprocessable();
    }

    private function position(): array
    {
        return ['eventId' => (string) Str::uuid(), 'readingRevision' => 1, 'pageNumber' => 24,
            'sentenceIndex' => 0, 'readAt' => now()->subMinute()->toISOString()];
    }

    private function reading(User $user): Reading
    {
        return Reading::create(['user_id' => $user->id, 'slug' => 'fixture', 'document' => [
            'title' => 'Reading', 'author' => 'Author', 'pages' => [
                ['number' => 24, 'columns' => [[[0, 'First.'], [1, 'Second.']]]],
            ], 'sentences' => [['text' => 'First.', 'translation' => 'First.'], ['text' => 'Second.', 'translation' => 'Second.']],
        ]])->refresh();
    }
}
