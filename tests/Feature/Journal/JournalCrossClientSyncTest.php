<?php

namespace Tests\Feature\Journal;

use App\Domain\Journal\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real HTTP route/authentication coverage; client storage/reconnect is tested in each app. */
class JournalCrossClientSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private array $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->tokens = [
            'ios' => $this->owner->createToken('iOS sync test')->plainTextToken,
            'android' => $this->owner->createToken('Android sync test')->plainTextToken,
        ];
    }

    #[DataProvider('directions')]
    public function test_create_edit_delete_are_visible_to_the_other_client(string $writer, string $reader): void
    {
        $url = '/api/journal/entries/'.strtolower((string) Str::ulid());
        $this->client($writer)->putJson($url, $this->draft())->assertCreated();
        $this->client($reader)->getJson($url)->assertOk()->assertJsonPath('data.body', '最初の文章。');
        $this->getJson('/api/journal/entries')->assertOk()->assertJsonCount(1, 'data');
        $this->client($reader)->putJson($url, $this->draft('別の端末で編集した。', 1))->assertOk();
        $this->client($writer)->getJson($url)->assertOk()->assertJsonPath('data.body', '別の端末で編集した。');
        $this->client($reader)->deleteJson($url, ['expectedVersion' => 2])->assertNoContent();
        $this->client($writer)->getJson('/api/journal/entries')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url)->assertNotFound();
    }

    #[DataProvider('directions')]
    public function test_reconnected_stale_edits_and_deletes_cannot_destroy_newer_writing(string $offline, string $online): void
    {
        $url = '/api/journal/entries/'.strtolower((string) Str::ulid());
        $this->client($offline)->putJson($url, $this->draft())->assertCreated();
        // The first client retains version 1 while the second saves version 2.
        $this->client($online)->putJson($url, $this->draft('オンラインの変更。', 1))->assertOk();
        $this->client($offline)->putJson($url, $this->draft('オフラインの変更。', 1))->assertConflict();
        $this->deleteJson($url, ['expectedVersion' => 1])->assertConflict();
        $this->getJson($url)->assertOk()->assertJsonPath('data.body', 'オンラインの変更。')->assertJsonPath('data.version', 2);
        $this->deleteJson($url, ['expectedVersion' => 2])->assertNoContent();
        $this->client($online)->getJson('/api/journal/entries')->assertOk()->assertJsonCount(0, 'data');
    }

    #[DataProvider('directions')]
    public function test_lost_acknowledgements_retry_once_and_deleted_ids_never_resurrect(string $offline, string $online): void
    {
        $id = strtolower((string) Str::ulid());
        $url = '/api/journal/entries/'.$id;
        // Retry the same offline-created identity after losing the first response.
        $this->client($offline)->putJson($url, $this->draft())->assertCreated();
        $this->putJson($url, $this->draft())->assertOk()->assertJsonPath('data.version', 1);
        $this->client($online)->getJson('/api/journal/entries')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson($url, ['expectedVersion' => 1])->assertNoContent();
        $this->client($offline)->putJson($url, $this->draft('オフラインで書いた。', 1))->assertNotFound();
        $this->putJson($url, $this->draft())->assertNotFound();
        $this->deleteJson($url, ['expectedVersion' => 1])->assertNoContent();
        $this->getJson('/api/journal/entries')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, JournalEntry::withTrashed()->count());
        $this->assertSoftDeleted('journal_entries', ['id' => $id]);
    }

    public static function directions(): array
    {
        return [
            'iOS to Android' => ['ios', 'android'], 'Android to iOS' => ['android', 'ios'],
            'iOS to web' => ['ios', 'web'], 'web to iOS' => ['web', 'ios'],
            'Android to web' => ['android', 'web'], 'web to Android' => ['web', 'android'],
        ];
    }

    private function client(string $name): static
    {
        $this->flushHeaders();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        if ($name === 'web') {
            return $this->asConvoLabBrowser($this->owner);
        }

        return $this->withToken($this->tokens[$name]);
    }

    private function draft(string $body = '最初の文章。', int $version = 0): array
    {
        return ['title' => null, 'body' => $body, 'entryDate' => '2026-11-22', 'expectedVersion' => $version];
    }
}
