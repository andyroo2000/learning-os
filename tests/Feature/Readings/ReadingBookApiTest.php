<?php

namespace Tests\Feature\Readings;

use App\Domain\Readings\Models\Reading;
use App\Domain\Readings\Models\ReadingBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReadingBookApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_client_can_create_retry_and_rename_a_book(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('cli')->plainTextToken);
        $id = strtolower((string) Str::ulid());
        $payload = ['id' => strtoupper($id), 'title' => 'Forest Tales'];
        $this->postJson('/api/reading-books', $payload)->assertOk()->assertJsonPath('id', $id);
        $this->postJson('/api/reading-books', $payload)->assertOk()->assertJsonPath('revision', 1);
        $this->assertDatabaseCount('reading_books', 1);
        $this->patchJson('/api/reading-books/'.$id, ['title' => 'New title', 'expectedRevision' => 1])
            ->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('title', 'New title');
        $this->patchJson('/api/reading-books/'.$id, ['title' => 'New title', 'expectedRevision' => 1])->assertOk()->assertJsonPath('revision', 2);
        $this->patchJson('/api/reading-books/'.$id, ['title' => 'Stale title', 'expectedRevision' => 1])->assertConflict();
    }

    public function test_contents_are_ordered_and_removal_preserves_readings(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $book = $this->createBook();
        $one = $this->reading($user, 'one');
        $two = $this->reading($user, 'two');
        $endpoint = '/api/reading-books/'.$book.'/contents';
        $this->putJson($endpoint, ['expectedRevision' => 1, 'readingIds' => [$two->id, $one->id]])
            ->assertOk()->assertJsonPath('readings.0.id', $two->id)->assertJsonPath('readings.0.pageNumbers', [24]);
        $this->putJson($endpoint, ['expectedRevision' => 1, 'readingIds' => [$two->id, $one->id]])
            ->assertOk()->assertJsonPath('revision', 2);
        $this->putJson($endpoint, ['expectedRevision' => 2, 'readingIds' => [$one->id]])
            ->assertOk()->assertJsonCount(1, 'readings')->assertJsonPath('revision', 3);
        $this->assertDatabaseHas('readings', ['id' => $two->id]);
        $this->assertDatabaseMissing('reading_book_entries', ['reading_id' => $two->id]);
    }

    public function test_cross_owner_ids_and_duplicate_memberships_cannot_be_assigned(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);
        $book = $this->createBook();
        $reading = $this->reading($other, 'private');
        $endpoint = '/api/reading-books/'.$book.'/contents';
        $this->putJson($endpoint, ['expectedRevision' => 1, 'readingIds' => [$reading->id]])->assertNotFound();
        $owned = $this->reading($user, 'owned');
        $this->putJson($endpoint, ['expectedRevision' => 1, 'readingIds' => [$owned->id, strtoupper($owned->id)]])->assertUnprocessable();
        $this->putJson($endpoint, ['expectedRevision' => 1, 'readingIds' => [$owned->id]])->assertOk();
        $another = $this->createBook();
        $this->putJson('/api/reading-books/'.$another.'/contents', ['expectedRevision' => 1, 'readingIds' => [$owned->id]])->assertConflict();
        $this->actingAs($other)->getJson('/api/reading-books/'.$book)->assertNotFound();
        $this->putJson($endpoint, ['expectedRevision' => 2, 'readingIds' => []])->assertNotFound();
        $this->postJson('/api/reading-books', ['id' => $book, 'title' => 'Forest Tales'])->assertNotFound();
    }

    public function test_listing_is_paginated_and_invalid_queries_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach (range(1, 21) as $index) {
            $this->createBook();
        }
        $this->getJson('/api/reading-books')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('total', 21);
        $this->getJson('/api/reading-books?page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/reading-books?page[]=2')->assertUnprocessable();
        $this->getJson('/api/reading-books?viewAs=other')->assertUnprocessable();
        $this->assertSame(21, ReadingBook::where('user_id', $user->id)->count());
    }

    private function createBook(): string
    {
        $id = strtolower((string) Str::ulid());
        $this->postJson('/api/reading-books', ['id' => $id, 'title' => 'Forest Tales'])->assertOk();

        return $id;
    }

    private function reading(User $user, string $slug): Reading
    {
        return Reading::create(['user_id' => $user->id, 'slug' => $slug, 'document' => [
            'title' => $slug, 'author' => 'Author', 'pages' => [['number' => 24]], 'sentences' => [],
        ]]);
    }
}
