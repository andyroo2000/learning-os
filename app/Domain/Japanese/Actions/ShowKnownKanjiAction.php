<?php

namespace App\Domain\Japanese\Actions;

use App\Domain\Japanese\Models\JapaneseKnowledgeProfile;
use App\Domain\Japanese\Models\UserKnownKanji;
use App\Domain\Japanese\Models\WaniKaniConnection;

final class ShowKnownKanjiAction
{
    /** @return array{version: int, kanji: list<string>, manualKanji: list<string>, wanikani: array{connected: bool, lastSyncedAt: ?string}} */
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

        return [
            'version' => (int) ($profile?->knowledge_version ?? 0),
            'kanji' => $rows->pluck('character')->all(),
            'manualKanji' => $rows->whereNotNull('manually_added_at')->pluck('character')->values()->all(),
            'wanikani' => [
                'connected' => $connection !== null,
                'lastSyncedAt' => $connection?->last_synced_at?->toJSON(),
            ],
        ];
    }
}
