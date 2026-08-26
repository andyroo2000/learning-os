<?php

namespace App\Domain\Japanese\Actions;

use App\Domain\Japanese\Models\JapaneseKnowledgeProfile;
use App\Domain\Japanese\Models\UserKnownKanji;
use App\Domain\Japanese\Models\WaniKaniConnection;
use App\Domain\Study\Enums\AutomaticStudyVocabImportStatus;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use Illuminate\Support\Facades\DB;

final class ShowKnownKanjiAction
{
    /** @return array{version: int, kanji: list<string>, manualKanji: list<string>, wanikani: array{connected: bool, lastSyncedAt: ?string, reviewCount: ?int, reviewCountUpdatedAt: ?string, transferBridge: array{enabled: bool, importedVocabularyCount: int, pendingVocabularyCount: int, failedVocabularyCount: int, lastImportedAt: ?string}}} */
    public function handle(int $userId): array
    {
        $profile = JapaneseKnowledgeProfile::query()->where('user_id', $userId)->first();
        $rows = UserKnownKanji::query()
            ->where('user_id', $userId)
            ->where(function ($query): void {
                $query->whereNotNull('wanikani_passed_at')->orWhereNotNull('manually_added_at');
            })
            ->orderBy('character')
            ->get(['character', 'manually_added_at']);
        $connection = WaniKaniConnection::query()->where('user_id', $userId)->first();
        $automaticGroupCounts = StudyVocabVariantGroup::query()
            ->where('user_id', $userId)
            ->whereNotNull('wanikani_subject_id')
            ->selectRaw('automatic_import_status, COUNT(*) AS aggregate')
            ->groupBy('automatic_import_status')
            ->pluck('aggregate', 'automatic_import_status');
        $queuedWithoutGroupCount = DB::table('user_wanikani_assignments as assignments')
            ->leftJoin('study_vocab_variant_groups as groups', function ($join) use ($userId): void {
                $join->on('groups.wanikani_subject_id', '=', 'assignments.subject_id')
                    ->where('groups.user_id', '=', $userId);
            })
            ->where('assignments.user_id', $userId)
            ->whereNotNull('assignments.transfer_bridge_queued_at')
            ->whereNull('groups.id')
            ->count();

        return [
            'version' => (int) ($profile?->knowledge_version ?? 0),
            'kanji' => $rows->pluck('character')->all(),
            'manualKanji' => $rows->whereNotNull('manually_added_at')->pluck('character')->values()->all(),
            'wanikani' => [
                'connected' => $connection !== null,
                'lastSyncedAt' => $connection?->last_synced_at?->toJSON(),
                'reviewCount' => $connection?->review_count,
                'reviewCountUpdatedAt' => $connection?->review_count_updated_at?->toJSON(),
                'transferBridge' => [
                    'enabled' => $connection?->transfer_bridge_enabled ?? false,
                    'importedVocabularyCount' => (int) $automaticGroupCounts->get(AutomaticStudyVocabImportStatus::Imported->value, 0),
                    'pendingVocabularyCount' => $queuedWithoutGroupCount
                        + (int) $automaticGroupCounts->get(AutomaticStudyVocabImportStatus::Generating->value, 0),
                    'failedVocabularyCount' => (int) $automaticGroupCounts->get(AutomaticStudyVocabImportStatus::Error->value, 0),
                    'lastImportedAt' => $connection?->transfer_bridge_last_imported_at?->toJSON(),
                ],
            ],
        ];
    }
}
