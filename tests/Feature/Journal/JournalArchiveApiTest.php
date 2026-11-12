<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalArchiveApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_by_date_and_id_without_leaking_foreign_or_deleted_entries(): void
    {
        $user = $this->signIn();
        $earlier = JournalEntry::factory()->for($user)->create(['entry_date' => '2026-11-01']);
        $sameDay = JournalEntry::factory()->for($user)->count(3)->create(['entry_date' => '2026-11-12'])->sortByDesc('id')->values();
        JournalEntry::factory()->create(['entry_date' => '2026-12-01']);
        JournalEntry::factory()->for($user)->create(['entry_date' => '2026-12-01'])->delete();

        $first = $this->getJson('/api/journal/entries?limit=2')->assertOk();
        $this->assertSame($sameDay->take(2)->pluck('id')->all(), array_column($first->json('data'), 'id'));
        // Deleting the previous page's boundary must not skip the next entry.
        $sameDay[1]->delete();
        $next = $this->getJson('/api/journal/entries?limit=2&cursor='.urlencode($first->json('nextCursor')))->assertOk();
        $this->assertSame([$sameDay[2]->id, $earlier->id], array_column($next->json('data'), 'id'));
        $next->assertJsonPath('nextCursor', null);
    }

    public function test_empty_archive_and_unauthenticated_access(): void
    {
        $this->getJson('/api/journal/entries')->assertUnauthorized();
        $this->signIn();
        $this->getJson('/api/journal/entries')->assertOk()->assertExactJson(['data' => [], 'nextCursor' => null]);
    }

    #[DataProvider('badQueries')]
    public function test_invalid_pagination_is_rejected(string $query, string $field): void
    {
        $this->signIn();
        $this->getJson('/api/journal/entries?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function badQueries(): array
    {
        return [
            ['limit=0', 'limit'], ['limit=51', 'limit'], ['limit=1.5', 'limit'],
            ['cursor=garbage', 'cursor'], ['cursor=W10%3D', 'cursor'], ['cursor=bnVsbA%3D%3D', 'cursor'],
            ['cursor='.urlencode(base64_encode('{"entryDate":"2026-02-30","id":"wrong"}')), 'cursor'],
        ];
    }

    public function test_delete_preserves_newer_writing_then_succeeds_and_is_retry_safe(): void
    {
        $user = $this->signIn();
        $entry = JournalEntry::factory()->for($user)->create(['body' => '新しい文章。', 'version' => 2]);
        $url = '/api/journal/entries/'.strtoupper($entry->id);
        $this->deleteJson($url, ['expectedVersion' => 1])->assertConflict();
        $this->assertSame('新しい文章。', $entry->fresh()->body);
        $this->deleteJson($url, ['expectedVersion' => 2])->assertNoContent();
        $deletedAt = $entry->fresh()->deleted_at;
        $this->travel(1)->minute();
        $this->deleteJson($url, ['expectedVersion' => 2])->assertNoContent();
        $this->assertTrue($deletedAt->equalTo($entry->fresh()->deleted_at));
        $this->getJson($url)->assertNotFound();
        $this->getJson('/api/journal/entries')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_delete_requires_owner_authentication_and_a_version(): void
    {
        $owner = User::factory()->create();
        $entry = JournalEntry::factory()->for($owner)->create();
        $url = '/api/journal/entries/'.$entry->id;
        $this->deleteJson($url, ['expectedVersion' => 1])->assertUnauthorized();
        $this->signIn();
        $this->deleteJson($url, ['expectedVersion' => 1])->assertNotFound();
        $entry->delete();
        $this->deleteJson($url, ['expectedVersion' => 1])->assertNotFound();
        $this->deleteJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('expectedVersion');
    }
}
