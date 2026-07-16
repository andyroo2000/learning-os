<?php

namespace Tests\Feature\Study;

use App\Domain\Japanese\Models\UserKnownKanji;
use App\Domain\Japanese\Models\WaniKaniConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KnownKanjiApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_kanji_requires_authentication(): void
    {
        $this->getJson('/api/study/known-kanji')->assertUnauthorized();
        $this->patchJson('/api/study/known-kanji/manual', ['kanji' => '私', 'known' => true])->assertUnauthorized();
    }

    public function test_empty_known_kanji_response_does_not_materialize_state(): void
    {
        $this->signIn();

        $this->getJson('/api/study/known-kanji')
            ->assertOk()
            ->assertExactJson([
                'version' => 0,
                'kanji' => [],
                'manualKanji' => [],
                'wanikani' => [
                    'connected' => false,
                    'lastSyncedAt' => null,
                ],
            ]);

        $this->assertDatabaseCount('japanese_knowledge_profiles', 0);
    }

    public function test_manual_known_kanji_updates_the_effective_set_and_version(): void
    {
        $user = $this->signIn();

        $this->patchJson('/api/study/known-kanji/manual', ['kanji' => '私', 'known' => true])
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('kanji.0', '私')
            ->assertJsonPath('manualKanji.0', '私');

        $this->patchJson('/api/study/known-kanji/manual', ['kanji' => '私', 'known' => true])
            ->assertOk()
            ->assertJsonPath('version', 1);

        $this->patchJson('/api/study/known-kanji/manual', ['kanji' => '私', 'known' => false])
            ->assertOk()
            ->assertJsonPath('version', 2)
            ->assertJsonPath('kanji', []);

        $this->assertDatabaseMissing('user_known_kanji', [
            'user_id' => $user->id,
            'character' => '私',
        ]);
    }

    #[DataProvider('invalidManualKanjiProvider')]
    public function test_manual_known_kanji_rejects_non_kanji_values(mixed $kanji): void
    {
        $this->signIn();

        $this->patchJson('/api/study/known-kanji/manual', ['kanji' => $kanji, 'known' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kanji');
    }

    public static function invalidManualKanjiProvider(): array
    {
        return [
            'kana' => ['わ'],
            'multiple kanji' => ['会社'],
            'iteration mark' => ['々'],
            'array' => [['私']],
            'blank' => [''],
        ];
    }

    public function test_connect_validates_then_encrypts_the_api_token(): void
    {
        $user = $this->signIn();
        Http::fake(['api.wanikani.com/v2/user' => Http::response(['object' => 'user'], 200)]);

        $this->putJson('/api/study/wanikani', ['apiToken' => ' test-token '])
            ->assertOk()
            ->assertJsonPath('wanikani.connected', true);

        $connection = WaniKaniConnection::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('test-token', $connection->api_token);
        $this->assertNotSame('test-token', $connection->getRawOriginal('api_token'));
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_connect_rejects_a_token_wanikani_does_not_accept_without_persisting_it(): void
    {
        $user = $this->signIn();
        Http::fake(['api.wanikani.com/v2/user' => Http::response([], 401)]);

        $this->putJson('/api/study/wanikani', ['apiToken' => 'bad-token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('apiToken');

        $this->assertDatabaseMissing('wanikani_connections', ['user_id' => $user->id]);
    }

    public function test_disconnect_is_idempotent_and_preserves_ever_known_kanji(): void
    {
        $user = $this->signIn();
        Http::fake(['api.wanikani.com/v2/user' => Http::response(['object' => 'user'])]);

        $this->putJson('/api/study/wanikani', ['apiToken' => 'test-token'])->assertOk();

        $knownKanji = new UserKnownKanji;
        $knownKanji->user_id = $user->id;
        $knownKanji->character = '私';
        $knownKanji->wanikani_subject_id = 999;
        $knownKanji->wanikani_passed_at = now();
        $knownKanji->save();

        $this->deleteJson('/api/study/wanikani')->assertNoContent();
        $this->deleteJson('/api/study/wanikani')->assertNoContent();

        $this->getJson('/api/study/known-kanji')
            ->assertOk()
            ->assertJsonPath('wanikani.connected', false)
            ->assertJsonPath('kanji.0', '私');

        $this->assertDatabaseMissing('wanikani_connections', ['user_id' => $user->id]);
        $this->assertDatabaseHas('user_known_kanji', [
            'user_id' => $user->id,
            'character' => '私',
            'wanikani_subject_id' => 999,
        ]);
    }

    public function test_connect_reports_wanikani_outages_without_persisting_the_token(): void
    {
        $user = $this->signIn();
        Http::fake(['api.wanikani.com/v2/user' => Http::response([], 503)]);

        $this->putJson('/api/study/wanikani', ['apiToken' => 'test-token'])
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => 'WaniKani is temporarily unavailable.']);

        $this->assertDatabaseMissing('wanikani_connections', ['user_id' => $user->id]);
    }

    public function test_changing_tokens_resets_sync_cursors_but_reusing_the_token_does_not(): void
    {
        $user = $this->signIn();
        Http::fake(['api.wanikani.com/v2/user' => Http::response(['object' => 'user'])]);

        $this->putJson('/api/study/wanikani', ['apiToken' => 'first-token'])->assertOk();
        $connection = WaniKaniConnection::query()->where('user_id', $user->id)->firstOrFail();
        $connection->assignments_synced_through_at = now()->subHour();
        $connection->last_synced_at = now()->subHour();
        $connection->save();

        $this->putJson('/api/study/wanikani', ['apiToken' => 'first-token'])->assertOk();
        $this->assertNotNull($connection->fresh()->last_synced_at);

        $this->putJson('/api/study/wanikani', ['apiToken' => 'second-token'])->assertOk();
        $connection->refresh();
        $this->assertNull($connection->assignments_synced_through_at);
        $this->assertNull($connection->last_synced_at);
    }

    public function test_sync_adds_only_ever_passed_kanji_and_uses_incremental_updates_afterward(): void
    {
        $user = $this->signIn();
        Http::fake([
            'api.wanikani.com/v2/user' => Http::response(['object' => 'user']),
            'api.wanikani.com/v2/assignments*' => Http::sequence()
                ->push($this->assignmentCollection([
                    $this->assignment(440, '2026-07-15T12:00:00.000000Z'),
                    $this->assignment(441, null),
                ]))
                ->push($this->assignmentCollection([])),
            'api.wanikani.com/v2/subjects*' => Http::response($this->subjectCollection([
                $this->kanjiSubject(440, '一'),
            ])),
        ]);

        $this->putJson('/api/study/wanikani', ['apiToken' => 'test-token'])->assertOk();

        $this->postJson('/api/study/wanikani/sync')
            ->assertOk()
            ->assertExactJson(['added' => 1, 'effectiveTotal' => 1, 'version' => 1]);

        $this->postJson('/api/study/wanikani/sync')
            ->assertOk()
            ->assertExactJson(['added' => 0, 'effectiveTotal' => 1, 'version' => 1]);

        $this->assertDatabaseHas('user_known_kanji', [
            'user_id' => $user->id,
            'character' => '一',
            'wanikani_subject_id' => 440,
        ]);
        $this->assertDatabaseMissing('user_known_kanji', [
            'user_id' => $user->id,
            'wanikani_subject_id' => 441,
        ]);

        $assignmentRequests = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn ($request): bool => str_contains($request->url(), '/assignments'))
            ->values();
        $this->assertCount(2, $assignmentRequests);
        $this->assertStringNotContainsString('updated_after=', $assignmentRequests[0]->url());
        $this->assertStringContainsString('updated_after=', $assignmentRequests[1]->url());
    }

    public function test_removing_a_manual_marker_keeps_wanikani_evidence_known(): void
    {
        $user = $this->signIn();
        $knownKanji = new UserKnownKanji;
        $knownKanji->user_id = $user->id;
        $knownKanji->character = '私';
        $knownKanji->wanikani_subject_id = 999;
        $knownKanji->wanikani_passed_at = now();
        $knownKanji->manually_added_at = now();
        $knownKanji->save();

        $this->patchJson('/api/study/known-kanji/manual', ['kanji' => '私', 'known' => false])
            ->assertOk()
            ->assertJsonPath('version', 0)
            ->assertJsonPath('kanji.0', '私')
            ->assertJsonPath('manualKanji', []);
    }

    public function test_concurrent_sync_for_the_same_user_returns_conflict(): void
    {
        $user = $this->signIn();
        $lock = Cache::lock("wanikani-sync:user:{$user->id}", 30);
        $this->assertTrue($lock->get());

        try {
            $this->postJson('/api/study/wanikani/sync')
                ->assertConflict()
                ->assertExactJson(['message' => 'A WaniKani sync is already in progress.']);
        } finally {
            $lock->release();
        }
    }

    private function assignment(int $subjectId, ?string $passedAt): array
    {
        return [
            'object' => 'assignment',
            'data' => [
                'subject_id' => $subjectId,
                'subject_type' => 'kanji',
                'passed_at' => $passedAt,
            ],
        ];
    }

    private function assignmentCollection(array $assignments): array
    {
        return ['object' => 'collection', 'pages' => ['next_url' => null], 'data' => $assignments];
    }

    private function kanjiSubject(int $id, string $character): array
    {
        return ['id' => $id, 'object' => 'kanji', 'data' => ['characters' => $character]];
    }

    private function subjectCollection(array $subjects): array
    {
        return ['object' => 'collection', 'pages' => ['next_url' => null], 'data' => $subjects];
    }
}
