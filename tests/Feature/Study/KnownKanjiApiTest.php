<?php

namespace Tests\Feature\Study;

use App\Domain\Japanese\Models\UserKnownKanji;
use App\Domain\Japanese\Models\WaniKaniConnection;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->patchJson('/api/study/wanikani/transfer-bridge', ['enabled' => true])->assertUnauthorized();
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
                    'reviewCount' => null,
                    'reviewCountUpdatedAt' => null,
                    'transferBridge' => [
                        'enabled' => false,
                        'importedVocabularyCount' => 0,
                        'pendingVocabularyCount' => 0,
                        'failedVocabularyCount' => 0,
                        'lastImportedAt' => null,
                    ],
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
        $connection->vocabulary_assignments_synced_through_at = now()->subHour();
        $connection->last_synced_at = now()->subHour();
        $connection->review_count = 12;
        $connection->review_count_updated_at = now()->subHour();
        $connection->save();

        $this->putJson('/api/study/wanikani', ['apiToken' => 'first-token'])->assertOk();
        $this->assertNotNull($connection->fresh()->last_synced_at);

        $this->putJson('/api/study/wanikani', ['apiToken' => 'second-token'])->assertOk();
        $connection->refresh();
        $this->assertNull($connection->assignments_synced_through_at);
        $this->assertNull($connection->vocabulary_assignments_synced_through_at);
        $this->assertNull($connection->last_synced_at);
        $this->assertNull($connection->review_count);
        $this->assertNull($connection->review_count_updated_at);
    }

    #[DataProvider('wanikaniProcessOwnedSummaryFieldProvider')]
    public function test_wanikani_process_owned_summary_fields_are_mass_assignment_guarded(
        string $field,
        mixed $value,
    ): void {
        $this->expectException(MassAssignmentException::class);

        (new WaniKaniConnection)->fill([$field => $value]);
    }

    /** @return array<string, array{string, mixed}> */
    public static function wanikaniProcessOwnedSummaryFieldProvider(): array
    {
        return [
            'review count' => ['review_count', 12],
            'review count timestamp' => ['review_count_updated_at', '2026-08-24T18:00:00Z'],
            'transfer bridge enabled' => ['transfer_bridge_enabled', true],
            'transfer bridge enabled timestamp' => ['transfer_bridge_enabled_at', '2026-08-24T18:00:00Z'],
            'transfer bridge seeded timestamp' => ['transfer_bridge_seeded_at', '2026-08-24T18:00:00Z'],
            'transfer bridge imported timestamp' => ['transfer_bridge_last_imported_at', '2026-08-24T18:00:00Z'],
        ];
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
}
