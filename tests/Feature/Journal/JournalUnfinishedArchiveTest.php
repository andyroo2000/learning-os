<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\ListJournalEntries;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalUnfinishedArchiveTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('analysisStates')]
    public function test_only_unsubmitted_or_failed_current_writing_is_unfinished(string $status, bool $unfinished): void
    {
        $user = $this->signIn();
        $entry = JournalEntry::factory()->for($user)->create(['body' => '映画を見た。', 'version' => 2]);
        JournalAnalysis::factory()->create(['entry_id' => $entry->id, 'source_body' => $entry->body, 'source_version' => 1, 'status' => 'ready']);
        JournalAnalysis::factory()->create(['entry_id' => $entry->id, 'source_body' => $entry->body, 'source_version' => 2, 'status' => $status]);
        $this->getJson('/api/journal/entries?unfinished=1&limit=1')->assertOk()->assertJsonCount($unfinished ? 1 : 0, 'data');
        $this->getJson('/api/journal/entries?unfinished=0')->assertOk()->assertJsonPath('data.0.id', $entry->id);
    }

    public static function analysisStates(): array
    {
        return [['queued', false], ['processing', false], ['ready', false], ['failed', true]];
    }

    public function test_body_edits_resume_but_title_edits_keep_completed_status(): void
    {
        $user = $this->signIn();
        $entry = JournalEntry::factory()->for($user)->create(['body' => '映画を見た。', 'title' => 'Renamed', 'version' => 2]);
        JournalAnalysis::factory()->create(['entry_id' => $entry->id, 'source_body' => $entry->body, 'source_version' => 1, 'status' => 'ready']);
        $this->getJson('/api/journal/entries?unfinished=1')->assertOk()->assertExactJson(['data' => [], 'nextCursor' => null]);
        $entry->body = '友達と映画を見た。';
        $entry->save();
        $this->getJson('/api/journal/entries?unfinished=1')->assertOk()->assertJsonPath('data.0.id', $entry->id);
    }

    #[DataProvider('bodyEdits')]
    public function test_small_body_edits_are_unfinished(string $source, string $body): void
    {
        $user = $this->signIn();
        $entry = JournalEntry::factory()->for($user)->create(['body' => $body]);
        JournalAnalysis::factory()->create(['entry_id' => $entry->id, 'source_body' => $source, 'status' => 'ready']);
        $this->getJson('/api/journal/entries?unfinished=1')->assertOk()->assertJsonPath('data.0.id', $entry->id);
    }

    public static function bodyEdits(): array
    {
        return [['Movie', 'movie'], ['movie', 'movie '], ['映画', "映画\n"]];
    }

    #[DataProvider('blankBodies')]
    public function test_whitespace_without_writing_is_not_a_continuable_draft(string $body): void
    {
        $user = $this->signIn();
        JournalEntry::factory()->for($user)->create(['body' => $body]);
        $this->getJson('/api/journal/entries?unfinished=1')->assertOk()->assertExactJson(['data' => [], 'nextCursor' => null]);
    }

    public static function blankBodies(): array
    {
        return [[''], ['   '], ["\r\n\t"], ["\u{3000}\u{00a0}"]];
    }

    public function test_filtered_cursor_keeps_private_root_entries_in_order(): void
    {
        $user = $this->signIn();
        $drafts = JournalEntry::factory()->for($user)->count(2)->create(['body' => 'Unfinished'])->sortByDesc('id')->values();
        JournalEntry::factory()->create(['body' => 'Foreign']);
        JournalEntry::factory()->for($user)->create(['body' => 'Deleted'])->delete();
        JournalEntry::factory()->for($user)->create(['body' => 'Reply', 'root_entry_id' => $drafts[0]->id]);
        JournalEntry::factory()->for($user)->create(['body' => '']);
        $page = $this->getJson('/api/journal/entries?unfinished=1&limit=1')->assertOk()->assertJsonPath('data.0.id', $drafts[0]->id);
        $this->getJson('/api/journal/entries?unfinished=1&limit=1&cursor='.urlencode($page->json('nextCursor')))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $drafts[1]->id)->assertJsonPath('nextCursor', null);
    }

    public function test_direct_lookup_uses_one_query_regardless_of_archive_size(): void
    {
        $user = $this->signIn();
        $draft = JournalEntry::factory()->for($user)->create(['body' => 'Older draft', 'entry_date' => '2026-10-01']);
        $completed = JournalEntry::factory()->for($user)->count(35)->create(['body' => 'Completed']);
        foreach ($completed as $entry) {
            JournalAnalysis::factory()->create(['entry_id' => $entry->id, 'source_body' => $entry->body, 'status' => 'ready']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $page = app(ListJournalEntries::class)->handle($user->id, limit: 1, unfinished: true);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame([$draft->id], $page['entries']->pluck('id')->all());
        $this->assertCount(1, $queries);
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filter_is_rejected(string $query): void
    {
        $this->signIn();
        $this->getJson('/api/journal/entries?'.$query)->assertUnprocessable()->assertJsonValidationErrors('unfinished');
    }

    public static function invalidFilters(): array
    {
        return [['unfinished=maybe'], ['unfinished[]=1'], ['unfinished='], ['unfinished=2']];
    }

    public function test_filtered_archive_requires_authentication(): void
    {
        $this->getJson('/api/journal/entries?unfinished=1')->assertUnauthorized();
    }
}
