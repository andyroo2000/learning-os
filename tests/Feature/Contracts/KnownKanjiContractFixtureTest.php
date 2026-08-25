<?php

namespace Tests\Feature\Contracts;

use App\Domain\Japanese\Actions\ShowKnownKanjiAction;
use App\Domain\Japanese\Models\JapaneseKnowledgeProfile;
use App\Domain\Japanese\Models\UserKnownKanji;
use App\Domain\Japanese\Models\WaniKaniConnection;
use App\Domain\Study\Enums\AutomaticStudyVocabImportStatus;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Contracts\CompatibilityFixtureRepository;
use Tests\TestCase;

class KnownKanjiContractFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_kanji_action_matches_current_and_legacy_canonical_fixtures(): void
    {
        $this->travelTo(Carbon::parse('2026-08-25T12:00:00Z'), function (): void {
            $user = User::factory()->create();
            JapaneseKnowledgeProfile::query()->forceCreate([
                'user_id' => $user->id,
                'knowledge_version' => 42,
            ]);
            $this->knownKanji($user, '会', manuallyAdded: true, waniKaniSubjectId: 101);
            $this->knownKanji($user, '橋', manuallyAdded: false, waniKaniSubjectId: 102);
            $this->knownKanji($user, '社', manuallyAdded: true);
            WaniKaniConnection::query()->forceCreate([
                'user_id' => $user->id,
                'api_token' => 'fixture-token',
                'last_synced_at' => '2026-08-25T10:15:30Z',
                'review_count' => 17,
                'review_count_updated_at' => '2026-08-25T10:16:00Z',
                'transfer_bridge_enabled' => true,
                'transfer_bridge_enabled_at' => '2026-08-24T12:00:00Z',
                'transfer_bridge_last_imported_at' => '2026-08-25T11:00:00Z',
            ]);
            foreach ([
                AutomaticStudyVocabImportStatus::Imported,
                AutomaticStudyVocabImportStatus::Generating,
                AutomaticStudyVocabImportStatus::Error,
            ] as $index => $status) {
                $subjectId = 201 + $index;
                $this->waniKaniVocabulary($subjectId);
                StudyVocabVariantGroup::factory()->for($user)->create([
                    'wanikani_subject_id' => $subjectId,
                    'automatic_import_status' => $status,
                ]);
            }

            $payload = app(ShowKnownKanjiAction::class)->handle($user->id);
            $this->assertSame(
                CompatibilityFixtureRepository::case('known-kanji.v2', 'connected-transfer-bridge')['payload'],
                $payload,
            );

            // Older clients received this exact projection: the newer keys were
            // absent, never present with null values.
            $legacyPayload = $payload;
            unset(
                $legacyPayload['wanikani']['reviewCount'],
                $legacyPayload['wanikani']['reviewCountUpdatedAt'],
                $legacyPayload['wanikani']['transferBridge'],
            );
            $this->assertSame(
                CompatibilityFixtureRepository::case('known-kanji.v2', 'legacy-connected')['payload'],
                $legacyPayload,
            );
        });
    }

    public function test_transfer_bridge_patch_request_and_response_match_the_canonical_fixture(): void
    {
        $this->travelTo(Carbon::parse('2026-08-25T12:00:00Z'), function (): void {
            $user = $this->signIn();
            WaniKaniConnection::query()->forceCreate([
                'user_id' => $user->id,
                'api_token' => 'fixture-token',
                'transfer_bridge_enabled' => false,
            ]);
            $case = CompatibilityFixtureRepository::case('wanikani-transfer-bridge-update.v1', 'enable');

            $response = $this->patchJson('/api/study/wanikani/transfer-bridge', $case['request']);

            $response->assertOk();
            $this->assertSame($case['response'], $response->json());
            $this->assertDatabaseHas('wanikani_connections', [
                'user_id' => $user->id,
                'transfer_bridge_enabled' => true,
                'transfer_bridge_enabled_at' => '2026-08-25 12:00:00',
            ]);
        });
    }

    private function knownKanji(
        User $user,
        string $character,
        bool $manuallyAdded,
        ?int $waniKaniSubjectId = null,
    ): void {
        UserKnownKanji::query()->forceCreate([
            'user_id' => $user->id,
            'character' => $character,
            'wanikani_subject_id' => $waniKaniSubjectId,
            'wanikani_passed_at' => $waniKaniSubjectId === null ? null : '2026-08-20T12:00:00Z',
            'manually_added_at' => $manuallyAdded ? '2026-08-21T12:00:00Z' : null,
        ]);
    }

    private function waniKaniVocabulary(int $subjectId): void
    {
        DB::table('wanikani_subjects')->insert([
            'subject_id' => $subjectId,
            'subject_type' => 'vocabulary',
            'characters' => "語{$subjectId}",
            'normalized_key' => "語{$subjectId}",
            'readings' => '[]',
            'meanings' => '[]',
            'hidden_at' => null,
            'source_updated_at' => now(),
            'matcher_version' => 'contract-v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
