<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Actions\SaveJournalDraft;
use App\Domain\Journal\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalDraftApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication_and_valid_route_id(): void
    {
        $this->putJson($this->url(), $this->draft())->assertUnauthorized();
        $this->getJson($this->url())->assertUnauthorized();
        $this->signIn();
        $this->putJson('/api/journal/entries/not-an-id', $this->draft())->assertNotFound();
    }

    public function test_saves_exact_original_text_and_only_exposes_public_fields(): void
    {
        $user = $this->signIn();
        $id = (string) Str::ulid();
        $url = $this->url($id);
        $body = "  昨日は映画を見た。\n\n楽しかった！🍿\n";
        $response = $this->putJson($url, $this->draft([
            'body' => $body, 'title' => ' A movie night ', 'user_id' => 999, 'version' => 88,
        ]))->assertCreated()->assertJsonPath('data.id', strtolower($id))
            ->assertJsonPath('data.body', $body)->assertJsonPath('data.title', 'A movie night')
            ->assertJsonPath('data.version', 1)->assertJsonMissingPath('data.user_id');
        $this->getJson($url)->assertOk()->assertExactJson($response->json());
        $this->assertSame($user->id, JournalEntry::sole()->user_id);
    }

    public function test_empty_draft_and_blank_title_are_valid_and_zero_title_is_preserved(): void
    {
        $this->signIn();
        $url = $this->url();
        $this->putJson($url, $this->draft(['title' => '  ']))->assertCreated()
            ->assertJsonPath('data.title', null)->assertJsonPath('data.body', '');
        $this->putJson($url, $this->draft(['title' => '0', 'expectedVersion' => 1]))
            ->assertOk()->assertJsonPath('data.title', '0');
    }

    public function test_retry_is_a_no_op_but_stale_changes_cannot_overwrite_newer_work(): void
    {
        $this->signIn();
        $url = $this->url();
        $draft = $this->draft(['body' => '初めての文章。']);
        $first = $this->putJson($url, $draft)->assertCreated()->json('data');
        $this->travel(1)->minute();
        $this->putJson($url, $draft)->assertOk()->assertJsonPath('data.updatedAt', $first['updatedAt']);
        $second = $this->putJson($url, $this->draft(['body' => '新しい文章。', 'expectedVersion' => 1]))
            ->assertOk()->assertJsonPath('data.version', 2)->json('data');
        $this->putJson($url, $draft)->assertConflict()->assertJsonPath('reason', 'journal_version_conflict');
        $this->getJson($url)->assertOk()->assertJsonPath('data', $second);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_foreign_and_deleted_ids_cannot_be_read_overwritten_or_recreated(): void
    {
        $foreign = JournalEntry::factory()->create();
        $user = $this->signIn();
        $deleted = JournalEntry::factory()->for($user)->create();
        $deleted->delete();
        foreach ([$foreign, $deleted] as $entry) {
            $this->getJson($this->url($entry->id))->assertNotFound();
            $this->putJson($this->url($entry->id), $this->draft())->assertNotFound();
        }
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertSame('', $foreign->fresh()->body);
        $this->assertSoftDeleted($deleted);
    }

    #[DataProvider('invalidDrafts')]
    public function test_http_and_direct_action_reject_invalid_input(string $field, mixed $value): void
    {
        $user = $this->signIn();
        $draft = $this->draft([$field => $value]);
        $this->putJson($this->url(), $draft)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->expectException(ValidationException::class);
        app(SaveJournalDraft::class)->handle($user->id, (string) Str::ulid(), $draft);
    }

    public static function invalidDrafts(): array
    {
        return [
            ['body', null], ['body', ['text']], ['body', str_repeat('あ', 20001)],
            ['title', str_repeat('a', 256)], ['entryDate', '2026-02-30'],
            ['expectedVersion', -1], ['expectedVersion', 1.5],
        ];
    }

    public function test_missing_fields_are_rejected_and_unknown_ids_need_create_version(): void
    {
        $this->signIn();
        $this->putJson($this->url(), [])->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'body', 'entryDate', 'expectedVersion']);
        $this->putJson($this->url(), $this->draft(['expectedVersion' => 4]))->assertConflict();
        $this->assertDatabaseCount('journal_entries', 0);
    }

    private function draft(array $overrides = []): array
    {
        return array_replace(['title' => null, 'body' => '', 'entryDate' => '2026-11-12', 'expectedVersion' => 0], $overrides);
    }

    private function url(?string $id = null): string
    {
        return '/api/journal/entries/'.($id ?? Str::ulid());
    }
}
