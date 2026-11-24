<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class JournalAuthenticationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_out_entry_reads_require_authentication_before_existence_checks(): void
    {
        $entry = JournalEntry::factory()->create(['body' => 'Private writing']);
        foreach ([$entry->id, (string) Str::ulid()] as $id) {
            $this->getJson('/api/journal/entries/'.$id)
                ->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        }
    }

    public function test_signed_in_readers_cannot_distinguish_foreign_and_missing_entries(): void
    {
        $foreign = JournalEntry::factory()->create(['body' => 'Private writing']);
        $reader = $this->signIn();
        $own = JournalEntry::factory()->for($reader)->create(['body' => 'My writing']);
        foreach ([$foreign->id, (string) Str::ulid()] as $id) {
            $this->getJson('/api/journal/entries/'.$id)->assertNotFound();
        }
        $this->getJson('/api/journal/entries/'.$own->id)->assertOk()->assertJsonPath('data.body', 'My writing');
    }
}
