<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Models\JournalQuestion;
use App\Domain\Journal\Support\JournalAvatars;
use App\Http\Resources\Journal\JournalQuestionResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_public_avatars_are_cacheable_pngs_without_authentication(): void
    {
        foreach (JournalAvatars::NAMES as $name) {
            $response = $this->get('/api/journal/avatars/'.$name)->assertOk()
                ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
            $this->assertSame(resource_path('images/journal/'.$name.'.png'), $response->baseResponse->getFile()->getPathname());
        }
    }

    public function test_unknown_and_traversal_paths_are_not_served(): void
    {
        $this->get('/api/journal/avatars/unknown')->assertNotFound();
        $this->get('/api/journal/avatars/%2e%2e%2f%2eenv')->assertNotFound();
    }

    public function test_catalogs_supply_distinct_hosted_portraits(): void
    {
        $this->signIn();
        $voices = $this->getJson('/api/journal/voices')->assertOk()->json('data');
        $tutors = $this->getJson('/api/journal/tutor')->assertOk()->json('data.tutors');
        $urls = array_column([...$voices, ...$tutors], 'avatarUrl');
        $this->assertCount(8, array_unique($urls));
        foreach ($urls as $url) {
            $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        }
    }

    public function test_existing_tutor_snapshots_get_urls_without_exposing_voice_or_changing_identity(): void
    {
        $snapshot = ['id' => 'hana', 'name' => 'Hana', 'gender' => 'F', 'avatar' => 'hana-v1', 'voiceId' => 'private-voice'];
        $question = new JournalQuestion;
        $question->tutor = $snapshot;
        $data = (new JournalQuestionResource($question))->resolve();
        $this->assertSame('hana', $data['tutor']['id']);
        $this->assertSame(JournalAvatars::url('professor-female-v1'), $data['tutor']['avatarUrl']);
        $this->assertArrayNotHasKey('voiceId', $data['tutor']);
        $this->assertSame($snapshot, $question->tutor);
        $this->assertNull(JournalAvatars::tutor(null));
        $this->assertNull(JournalAvatars::tutor(['avatar' => 'future'])['avatarUrl']);
    }
}
