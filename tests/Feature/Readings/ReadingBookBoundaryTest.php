<?php

namespace Tests\Feature\Readings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReadingBookBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_contents_accept_500_owned_readings_and_reject_501_without_mutation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $book = strtolower((string) Str::ulid());
        $this->postJson('/api/reading-books', ['id' => $book, 'title' => 'Anthology'])->assertOk();
        $ids = array_map(fn () => strtolower((string) Str::ulid()), range(1, 501));
        foreach (array_chunk($ids, 100) as $chunk) {
            DB::table('readings')->insert(array_map(fn ($id) => [
                'id' => $id, 'user_id' => $user->id, 'slug' => $id,
                'document' => json_encode(['title' => 'Chapter', 'author' => 'Author', 'pages' => [['number' => 1]]]),
            ], $chunk));
        }
        $url = '/api/reading-books/'.$book.'/contents';
        $this->putJson($url, ['expectedRevision' => 1, 'readingIds' => array_slice($ids, 0, 500)])
            ->assertOk()->assertJsonCount(500, 'readings');
        $this->putJson($url, ['expectedRevision' => 2, 'readingIds' => $ids])->assertUnprocessable();
        $this->assertDatabaseCount('reading_book_entries', 500);
        $this->assertDatabaseHas('reading_books', ['id' => $book, 'revision' => 2]);
    }
}
