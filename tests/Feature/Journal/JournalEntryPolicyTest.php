<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalEntryPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('abilities')]
    public function test_only_the_owner_can_access_an_entry(string $ability): void
    {
        $entry = JournalEntry::factory()->create();
        $otherUser = User::factory()->create();

        $this->assertTrue(Gate::forUser($entry->user)->allows($ability, $entry));
        $denied = Gate::forUser($otherUser)->inspect($ability, $entry);
        $this->assertTrue($denied->denied());
        $this->assertSame(404, $denied->status());
    }

    public static function abilities(): array
    {
        return [['view'], ['update'], ['delete']];
    }

    public function test_delete_policy_still_hides_another_users_deleted_entry(): void
    {
        $entry = JournalEntry::factory()->create();
        $entry->delete();

        $this->assertTrue(Gate::forUser($entry->user)->allows('delete', $entry));
        $this->assertSame(404, Gate::forUser(User::factory()->create())->inspect('delete', $entry)->status());
    }
}
