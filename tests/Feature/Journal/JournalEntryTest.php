<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_untitled_draft_has_a_stable_id_owner_date_and_version(): void
    {
        $entry = JournalEntry::factory()->create()->fresh();

        $this->assertTrue(Str::isUlid($entry->id));
        $this->assertNull($entry->title);
        $this->assertSame('', $entry->body);
        $this->assertSame('2026-11-12', $entry->entry_date->format('Y-m-d'));
        $this->assertSame(1, $entry->version);
        $this->assertSame($entry->user_id, $entry->user->id);
    }

    public function test_writing_and_precise_timestamps_round_trip_without_normalization(): void
    {
        $body = "昨日は映画を見た。\n\n楽しかった！ 🍿\n";
        $entry = JournalEntry::factory()->create([
            'body' => $body,
            'title' => 'A movie night',
            'created_at' => '2026-11-12 12:00:00.123456',
            'updated_at' => '2026-11-12 12:00:00.654321',
        ])->fresh();

        $this->assertSame($body, $entry->body);
        $this->assertSame('A movie night', $entry->title);
        $this->assertSame('123456', $entry->created_at->format('u'));
        $this->assertSame('654321', $entry->updated_at->format('u'));
    }

    public function test_owner_cannot_be_reassigned(): void
    {
        $entry = JournalEntry::factory()->create();
        $ownerId = $entry->user_id;
        $entry->user_id = User::factory()->create()->id;
        try {
            $entry->save();
            $this->fail('Ownership transfer should fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Journal entry owner cannot be changed.', $exception->getMessage());
        }
        $this->assertSame($ownerId, $entry->fresh()->user_id);
    }

    #[DataProvider('guardedFields')]
    public function test_fields_cannot_bypass_actions_through_mass_assignment(string $field): void
    {
        $this->expectException(MassAssignmentException::class);
        (new JournalEntry)->fill([$field => 'untrusted']);
    }

    public static function guardedFields(): array
    {
        return array_map(fn ($field) => [$field], [
            'id', 'user_id', 'version', 'deleted_at', 'created_at', 'updated_at',
            'title', 'body', 'entry_date', 'audio_voice_id', 'audio_text_hash', 'root_entry_id', 'question_id', 'conversation_position',
        ]);
    }

    public function test_deleted_entries_are_hidden_but_retained_until_account_deletion(): void
    {
        $entry = JournalEntry::factory()->create(['body' => '保存する文章。']);
        $entry->delete();

        $this->assertNull(JournalEntry::find($entry->id));
        $this->assertSame('保存する文章。', JournalEntry::withTrashed()->findOrFail($entry->id)->body);
        $entry->user->delete();
        $this->assertDatabaseMissing('journal_entries', ['id' => $entry->id]);
    }

    public function test_lowercase_route_binding_resolves_the_entry(): void
    {
        $entry = JournalEntry::factory()->create();
        $resolved = (new JournalEntry)->resolveRouteBinding(strtolower($entry->id));

        $this->assertSame($entry->id, $resolved->id);
    }
}
