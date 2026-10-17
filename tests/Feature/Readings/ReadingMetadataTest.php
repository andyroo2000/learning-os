<?php

namespace Tests\Feature\Readings;

use App\Domain\Readings\Models\Reading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReadingMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_documents_are_backfilled_and_book_contents_do_not_select_documents(): void
    {
        $user = User::factory()->create();
        $reading = Reading::create(['user_id' => $user->id, 'slug' => 'example', 'document' => [
            'title' => 'Original title', 'author' => 'Author',
            'pages' => [['number' => 24], ['number' => 30]],
        ]]);
        $migration = require base_path('database/migrations/2026_10_17_210000_add_reading_metadata.php');
        $migration->down();
        $migration->up();
        $reading->refresh();
        $this->assertSame('Original title', $reading->title);
        $this->assertSame([24, 30], $reading->page_numbers);
        $book = strtolower((string) Str::ulid());
        $this->actingAs($user)->postJson('/api/reading-books', ['id' => $book, 'title' => 'Collection'])->assertOk();
        $this->putJson('/api/reading-books/'.$book.'/contents', ['expectedRevision' => 1, 'readingIds' => [$reading->id]])->assertOk();
        DB::enableQueryLog();
        $this->getJson('/api/reading-books/'.$book)->assertOk()->assertJsonPath('readings.0.pageNumbers', [24, 30]);
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($query) => str_contains($query, 'join "reading_book_entries"'));
        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('*', $queries->first());
        $this->assertStringNotContainsString('document', $queries->first());
        DB::disableQueryLog();
    }
}
