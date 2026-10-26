<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudyPile;
use App\Domain\Study\Models\StudyPileChapter;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyPileCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_default_to_inactive_and_parent_toggles_preserve_chapters(): void
    {
        $user = $this->signIn();
        $pileId = strtolower((string) Str::ulid());
        $chapterId = strtolower((string) Str::ulid());
        $url = '/api/study/piles/'.strtoupper($pileId);
        $this->withoutMiddleware(TrimStrings::class);

        $this->putJson($url, ['title' => ' Genki II ', 'source_reference' => ' third edition '])
            ->assertOk()->assertJsonPath('pile.id', $pileId)
            ->assertJsonPath('pile.title', 'Genki II')->assertJsonPath('pile.is_active', false)
            ->assertJsonPath('pile.source_reference', 'third edition');
        $this->putJson("{$url}/chapters/{$chapterId}", ['title' => 'Chapter 21', 'position' => 21])
            ->assertOk()->assertJsonPath('chapter.is_active', false);
        $this->putJson("{$url}/chapters/{$chapterId}", ['is_active' => true])->assertOk();
        $this->putJson($url, ['is_active' => true])->assertOk();
        $this->putJson($url, ['is_active' => false])->assertOk()
            ->assertJsonPath('pile.chapters.0.is_active', true);

        $this->assertSame($user->id, StudyPile::findOrFail($pileId)->user_id);
        $this->assertTrue(StudyPileChapter::findOrFail($chapterId)->is_active);
        $entry = SyncFeedEntry::where('resource_type', 'pile_chapter')->where('operation', 'create')->sole();
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame($pileId, $entry->payload['pile_id']);
        $this->assertFalse($entry->payload['is_active']);
        $this->assertSame(21, $entry->payload['position']);
    }

    public function test_retries_and_empty_updates_do_not_duplicate_sync_or_clear_metadata(): void
    {
        $this->signIn();
        $url = '/api/study/piles/'.Str::ulid();
        $payload = ['title' => 'Quartet 1', 'position' => '2', 'is_active' => '0', 'source_reference' => 'edition 1'];
        $original = $this->putJson($url, $payload)->assertOk()->json('pile');
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('pile.updated_at', $original['updated_at']);
        $this->putJson($url, [])->assertOk()->assertJsonPath('pile.source_reference', 'edition 1');
        $this->assertSame(1, SyncFeedEntry::count());
        $this->putJson($url, ['source_reference' => null])->assertOk()->assertJsonPath('pile.source_reference', null);
        $this->assertSame(2, SyncFeedEntry::count());
    }

    public function test_lists_owned_piles_with_stable_pagination_and_numeric_chapter_order(): void
    {
        $this->signIn();
        $first = strtolower((string) Str::ulid());
        $second = strtolower((string) Str::ulid());
        $this->putJson('/api/study/piles/'.$second, ['title' => 'Quartet', 'position' => 2])->assertOk();
        $this->putJson('/api/study/piles/'.$first, ['title' => 'Genki', 'position' => 1])->assertOk();
        foreach ([21, 20] as $position) {
            $this->putJson('/api/study/piles/'.$first.'/chapters/'.Str::ulid(), [
                'title' => 'Chapter '.$position, 'position' => $position,
            ])->assertOk();
        }
        $this->getJson('/api/study/piles?limit=1')->assertOk()
            ->assertJsonPath('piles.0.id', $first)->assertJsonPath('next_cursor', 1)
            ->assertJsonPath('piles.0.chapters.0.position', 20)->assertJsonPath('total', 2);
        $this->getJson('/api/study/piles?limit=1&cursor=1')->assertOk()
            ->assertJsonPath('piles.0.id', $second)->assertJsonPath('next_cursor', null);
        $this->signIn();
        $this->getJson('/api/study/piles')->assertOk()->assertJsonCount(0, 'piles');
        $this->putJson('/api/study/piles/'.$first, ['title' => 'Stolen'])->assertNotFound();
        $this->putJson('/api/study/piles/'.$first.'/chapters/'.Str::ulid(), ['title' => 'Other'])->assertNotFound();
    }

    public function test_chapters_cannot_move_between_books(): void
    {
        $this->signIn();
        $first = (string) Str::ulid();
        $second = (string) Str::ulid();
        $chapter = (string) Str::ulid();
        foreach ([$first, $second] as $id) {
            $this->putJson('/api/study/piles/'.$id, ['title' => 'Book'])->assertOk();
        }
        $this->putJson("/api/study/piles/{$first}/chapters/{$chapter}", ['title' => 'Chapter'])->assertOk();
        $this->putJson("/api/study/piles/{$second}/chapters/{$chapter}", ['title' => 'Moved'])->assertNotFound();
        $this->assertSame(strtolower($first), StudyPileChapter::sole()->pile_id);
    }

    public function test_validation_rejects_malformed_fields_and_requires_a_title_on_create(): void
    {
        $this->signIn();
        $url = '/api/study/piles/'.Str::ulid();
        $this->putJson($url, ['is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->putJson($url, [
            'title' => [], 'is_active' => 'yes', 'position' => -1, 'source_reference' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['title', 'is_active', 'position', 'source_reference']);
        $this->getJson('/api/study/piles?limit[]=1&cursor=-1')->assertUnprocessable()
            ->assertJsonValidationErrors(['limit', 'cursor']);
        $this->assertSame(0, StudyPile::count());
        $this->assertSame(0, SyncFeedEntry::count());
    }

    public function test_catalog_endpoints_require_authentication(): void
    {
        $this->getJson('/api/study/piles')->assertUnauthorized();
        $this->putJson('/api/study/piles/'.Str::ulid(), ['title' => 'Book'])->assertUnauthorized();
    }
}
