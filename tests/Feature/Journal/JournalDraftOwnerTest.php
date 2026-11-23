<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalDraftOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_browser_account_cannot_upload_private_offline_writing(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->asConvoLabBrowser($other)
            ->withHeader('X-Journal-Owner', (string) $owner->id)
            ->putJson($this->url(), $this->draft())->assertForbidden();
        $this->assertSame(0, JournalEntry::withTrashed()->count());
    }

    public function test_owner_header_does_not_authenticate_an_anonymous_request(): void
    {
        $owner = User::factory()->create();
        $this->withHeader('X-Journal-Owner', (string) $owner->id)
            ->putJson($this->url(), $this->draft())->assertUnauthorized();
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_matching_account_and_legacy_clients_keep_the_same_retry_contract(): void
    {
        $owner = User::factory()->create();
        $url = $this->url();
        $this->asConvoLabBrowser($owner)->withHeader('X-Journal-Owner', (string) $owner->id)
            ->putJson($url, $this->draft())->assertCreated()->assertJsonPath('data.version', 1);
        $this->flushHeaders();
        $this->putJson($url, $this->draft())->assertOk()->assertJsonPath('data.version', 1);
        $this->assertSame($owner->id, JournalEntry::sole()->user_id);
    }

    #[DataProvider('invalidOwners')]
    public function test_present_malformed_owner_headers_do_not_bypass_the_guard(string $header): void
    {
        $owner = User::factory()->create(['id' => 123]);
        $this->asConvoLabBrowser($owner)->withHeader('X-Journal-Owner', $header)
            ->putJson($this->url(), $this->draft())->assertForbidden();
        $this->assertSame(0, JournalEntry::count());
    }

    public static function invalidOwners(): array
    {
        return [[''], ['0123'], ['123x'], ['+123'], ['123,123']];
    }

    private function url(): string
    {
        return '/api/journal/entries/'.strtolower((string) Str::ulid());
    }

    private function draft(): array
    {
        return ['title' => null, 'body' => 'Private offline writing', 'entryDate' => '2026-11-22', 'expectedVersion' => 0];
    }
}
