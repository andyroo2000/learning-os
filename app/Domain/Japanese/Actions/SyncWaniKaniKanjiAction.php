<?php

namespace App\Domain\Japanese\Actions;

use App\Domain\Japanese\Exceptions\WaniKaniSyncInProgressException;
use App\Domain\Japanese\Models\JapaneseKnowledgeProfile;
use App\Domain\Japanese\Models\UserKnownKanji;
use App\Domain\Japanese\Models\WaniKaniConnection;
use App\Domain\Japanese\Services\WaniKaniApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class SyncWaniKaniKanjiAction
{
    private const OVERLAP_MINUTES = 5;

    public function __construct(private readonly WaniKaniApiClient $client) {}

    /** @return array{added: int, effectiveTotal: int, version: int} */
    public function handle(int $userId): array
    {
        $lock = Cache::lock("wanikani-sync:user:{$userId}", 300);
        if (! $lock->get()) {
            throw new WaniKaniSyncInProgressException;
        }

        try {
            $connection = WaniKaniConnection::query()->where('user_id', $userId)->firstOrFail();
            $syncStartedAt = CarbonImmutable::now('UTC');
            $updatedAfter = $connection->assignments_synced_through_at?->subMinutes(self::OVERLAP_MINUTES);
            $passedKanji = $this->client->passedKanji((string) $connection->api_token, $updatedAfter);

            return DB::transaction(function () use ($userId, $connection, $syncStartedAt, $passedKanji): array {
                $profile = JapaneseKnowledgeProfile::lockForUser($userId);
                $added = 0;

                foreach ($passedKanji as $passed) {
                    $row = UserKnownKanji::query()
                        ->where('user_id', $userId)
                        ->where('character', $passed->character)
                        ->lockForUpdate()
                        ->first();
                    $wasKnown = $row?->isEffectivelyKnown() ?? false;

                    $row ??= new UserKnownKanji;
                    $row->user_id = $userId;
                    $row->character = $passed->character;
                    $row->wanikani_subject_id = $passed->subjectId;
                    if ($row->wanikani_passed_at === null || $passed->passedAt->isBefore($row->wanikani_passed_at)) {
                        $row->wanikani_passed_at = $passed->passedAt;
                    }
                    $row->save();

                    if (! $wasKnown) {
                        $added++;
                    }
                }

                if ($added > 0) {
                    $profile->increment('knowledge_version', $added);
                    $profile->refresh();
                }

                $lockedConnection = WaniKaniConnection::query()
                    ->whereKey($connection->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedConnection->assignments_synced_through_at = $syncStartedAt;
                $lockedConnection->last_synced_at = $syncStartedAt;
                $lockedConnection->save();

                $effectiveTotal = UserKnownKanji::query()
                    ->where('user_id', $userId)
                    ->where(function ($query): void {
                        $query->whereNotNull('wanikani_passed_at')->orWhereNotNull('manually_added_at');
                    })
                    ->count();

                return [
                    'added' => $added,
                    'effectiveTotal' => $effectiveTotal,
                    'version' => (int) $profile->knowledge_version,
                ];
            });
        } finally {
            $lock->release();
        }
    }
}
