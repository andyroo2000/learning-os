<?php

namespace App\Domain\Japanese\Actions;

use App\Domain\Japanese\Data\WaniKaniPassedKanji;
use App\Domain\Japanese\Data\WaniKaniVocabularyProgress;
use App\Domain\Japanese\Exceptions\WaniKaniApiException;
use App\Domain\Japanese\Exceptions\WaniKaniSyncInProgressException;
use App\Domain\Japanese\Models\JapaneseKnowledgeProfile;
use App\Domain\Japanese\Models\UserKnownKanji;
use App\Domain\Japanese\Models\WaniKaniConnection;
use App\Domain\Japanese\Services\WaniKaniApiClient;
use App\Domain\Study\Services\WaniKaniVocabularyConceptMatcher;
use App\Domain\Study\Support\LearningConceptText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncWaniKaniKanjiAction
{
    private const OVERLAP_MINUTES = 5;

    public function __construct(
        private readonly WaniKaniApiClient $client,
        private readonly WaniKaniVocabularyConceptMatcher $vocabularyMatcher,
        private readonly DispatchWaniKaniTransferImportsAction $dispatchTransferImports,
    ) {}

    /**
     * @return array{added: int, effectiveTotal: int, version: int, reviewCount: int|null, vocabularyAdded: int, vocabularyKnownTotal: int, vocabularyMatchedTotal: int}
     */
    public function handle(int $userId): array
    {
        $lock = Cache::lock("wanikani-sync:user:{$userId}", 300);
        if (! $lock->get()) {
            throw new WaniKaniSyncInProgressException;
        }

        try {
            $result = $this->synchronize($userId);

            try {
                // Keep candidate selection behind the same sync lock so it always observes the
                // vocabulary progress persisted by this provider response.
                $this->dispatchTransferImports->handle($userId);
            } catch (Throwable $exception) {
                report($exception);
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{added: int, effectiveTotal: int, version: int, reviewCount: int|null, vocabularyAdded: int, vocabularyKnownTotal: int, vocabularyMatchedTotal: int}
     */
    private function synchronize(int $userId): array
    {
        $connection = WaniKaniConnection::query()->where('user_id', $userId)->firstOrFail();
        $syncStartedAt = CarbonImmutable::now('UTC');
        $apiToken = (string) $connection->api_token;
        $passedKanji = $this->client->passedKanji(
            $apiToken,
            $connection->assignments_synced_through_at?->subMinutes(self::OVERLAP_MINUTES),
        );
        $vocabularyProgress = $this->client->vocabularyProgress(
            $apiToken,
            $connection->vocabulary_assignments_synced_through_at?->subMinutes(self::OVERLAP_MINUTES),
        );
        $vocabularyMatches = $this->vocabularyMatcher->match($vocabularyProgress);
        $reviewCount = $this->fetchReviewCount($connection);

        return DB::transaction(fn (): array => $this->persistSync([
            'connection' => $connection,
            'synced_at' => $syncStartedAt,
            'passed_kanji' => $passedKanji,
            'vocabulary_progress' => $vocabularyProgress,
            'vocabulary_matches' => $vocabularyMatches,
            'review_count' => $reviewCount,
        ]));
    }

    /** @return array{fetched: bool, value: int|null} */
    private function fetchReviewCount(WaniKaniConnection $connection): array
    {
        try {
            return [
                'fetched' => true,
                'value' => $this->client->immediateReviewCount((string) $connection->api_token),
            ];
        } catch (WaniKaniApiException $exception) {
            Log::warning('WaniKani review count refresh failed; preserving the cached count.', [
                'user_id' => $connection->user_id,
                'status' => $exception->getCode(),
            ]);

            return ['fetched' => false, 'value' => null];
        }
    }

    /**
     * @param  array{connection: WaniKaniConnection, synced_at: CarbonImmutable, passed_kanji: list<WaniKaniPassedKanji>, vocabulary_progress: list<WaniKaniVocabularyProgress>, vocabulary_matches: list<array{subject_id: int, concept_id: string, match_method: string, confidence: float}>, review_count: array{fetched: bool, value: int|null}}  $sync
     * @return array{added: int, effectiveTotal: int, version: int, reviewCount: int|null, vocabularyAdded: int, vocabularyKnownTotal: int, vocabularyMatchedTotal: int}
     */
    private function persistSync(array $sync): array
    {
        $connection = $sync['connection'];
        $userId = (int) $connection->user_id;
        $profile = JapaneseKnowledgeProfile::lockForUser($userId);
        $added = $this->persistPassedKanji($connection, $sync['passed_kanji']);
        $vocabularyAdded = $this->persistVocabularyProgress(
            $connection,
            $sync['vocabulary_progress'],
            $sync['vocabulary_matches'],
            $sync['synced_at'],
        );
        $this->reconcileStaleVocabularyMatches($connection, $sync['synced_at']);
        $this->incrementKnowledgeVersion($profile, $added);
        $lockedConnection = $this->updateConnection($connection, $sync['synced_at'], $sync['review_count']);

        return [
            'added' => $added,
            'effectiveTotal' => $this->effectiveKanjiTotal($connection),
            'version' => (int) $profile->knowledge_version,
            'reviewCount' => $lockedConnection->review_count,
            'vocabularyAdded' => $vocabularyAdded,
            'vocabularyKnownTotal' => $this->knownVocabularyTotal($connection),
            'vocabularyMatchedTotal' => $this->matchedVocabularyTotal($connection),
        ];
    }

    /** @param list<WaniKaniPassedKanji> $passedKanji */
    private function persistPassedKanji(WaniKaniConnection $connection, array $passedKanji): int
    {
        $userId = (int) $connection->user_id;
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

        return $added;
    }

    private function incrementKnowledgeVersion(JapaneseKnowledgeProfile $profile, int $added): void
    {
        if ($added === 0) {
            return;
        }

        $profile->increment('knowledge_version', $added);
        $profile->refresh();
    }

    /** @param array{fetched: bool, value: int|null} $reviewCount */
    private function updateConnection(
        WaniKaniConnection $connection,
        CarbonImmutable $syncStartedAt,
        array $reviewCount,
    ): WaniKaniConnection {
        $lockedConnection = WaniKaniConnection::query()
            ->whereKey($connection->getKey())
            ->lockForUpdate()
            ->firstOrFail();
        $lockedConnection->assignments_synced_through_at = $syncStartedAt;
        $lockedConnection->vocabulary_assignments_synced_through_at = $syncStartedAt;
        $lockedConnection->last_synced_at = $syncStartedAt;
        if ($reviewCount['fetched']) {
            $lockedConnection->review_count = $reviewCount['value'];
            $lockedConnection->review_count_updated_at = $syncStartedAt;
        }
        $lockedConnection->save();

        return $lockedConnection;
    }

    private function effectiveKanjiTotal(WaniKaniConnection $connection): int
    {
        return UserKnownKanji::query()
            ->where('user_id', $connection->user_id)
            ->where(function ($query): void {
                $query->whereNotNull('wanikani_passed_at')->orWhereNotNull('manually_added_at');
            })
            ->count();
    }

    private function knownVocabularyTotal(WaniKaniConnection $connection): int
    {
        return DB::table('user_wanikani_assignments')
            ->where('user_id', $connection->user_id)
            ->whereNotNull('passed_at')
            ->count();
    }

    private function matchedVocabularyTotal(WaniKaniConnection $connection): int
    {
        return DB::table('user_wanikani_assignments as assignments')
            ->join(
                'wanikani_subject_learning_concepts as links',
                'links.subject_id',
                '=',
                'assignments.subject_id',
            )
            ->where('assignments.user_id', $connection->user_id)
            ->whereNotNull('assignments.passed_at')
            ->distinct()
            ->count('links.concept_id');
    }

    /**
     * @param  list<WaniKaniVocabularyProgress>  $progress
     * @param  list<array{subject_id: int, concept_id: string, match_method: string, confidence: float}>  $matches
     */
    private function persistVocabularyProgress(
        WaniKaniConnection $connection,
        array $progress,
        array $matches,
        CarbonImmutable $syncedAt,
    ): int {
        if ($progress === []) {
            return 0;
        }

        $rows = $this->prepareVocabularyRows($connection, $progress, $syncedAt);
        $this->upsertVocabularySubjects($rows['subjects']);

        // Serialize global subject-to-concept rematches across users. The existing
        // per-user cache lock cannot prevent two accounts syncing the same subject.
        DB::table('wanikani_subjects')
            ->whereIn('subject_id', $rows['subject_ids'])
            ->orderBy('subject_id')
            ->lockForUpdate()
            ->get(['subject_id']);

        $this->upsertVocabularyAssignments($rows['assignments']);
        $this->replaceVocabularyMatches($rows['subject_ids'], $matches, $syncedAt);

        return $rows['added'];
    }

    /**
     * @param  list<WaniKaniVocabularyProgress>  $progress
     * @return array{subject_ids: list<int>, subjects: list<array<string, mixed>>, assignments: list<array<string, mixed>>, added: int}
     */
    private function prepareVocabularyRows(
        WaniKaniConnection $connection,
        array $progress,
        CarbonImmutable $syncedAt,
    ): array {
        $userId = (int) $connection->user_id;
        $subjectIds = array_values(array_unique(array_map(
            fn ($item): int => $item->subjectId,
            $progress,
        )));
        $existingPassedAt = DB::table('user_wanikani_assignments')
            ->where('user_id', $userId)
            ->whereIn('subject_id', $subjectIds)
            ->pluck('passed_at', 'subject_id');
        $vocabularyAdded = 0;
        $subjectRows = [];
        $assignmentRows = [];

        foreach ($progress as $item) {
            $previousPassedAt = $existingPassedAt->get($item->subjectId);
            $passedAt = $item->passedAt ?? $previousPassedAt;
            if ($item->passedAt !== null && $previousPassedAt === null) {
                $vocabularyAdded++;
            }

            $subjectRows[] = [
                'subject_id' => $item->subjectId,
                'subject_type' => $item->subjectType,
                'characters' => $item->characters,
                'normalized_key' => LearningConceptText::normalize($item->characters),
                'readings' => json_encode($item->readings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'meanings' => json_encode($item->meanings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'hidden_at' => $item->hiddenAt,
                'source_updated_at' => $item->subjectUpdatedAt,
                'matcher_version' => WaniKaniVocabularyConceptMatcher::VERSION,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
            $assignmentRows[] = [
                'user_id' => $userId,
                'subject_id' => $item->subjectId,
                'srs_stage' => $item->srsStage,
                'passed_at' => $passedAt,
                'burned_at' => $item->burnedAt,
                'hidden' => $item->hidden,
                'source_updated_at' => $item->assignmentUpdatedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        return [
            'subject_ids' => $subjectIds,
            'subjects' => $subjectRows,
            'assignments' => $assignmentRows,
            'added' => $vocabularyAdded,
        ];
    }

    /** @param list<array<string, mixed>> $subjectRows */
    private function upsertVocabularySubjects(array $subjectRows): void
    {
        foreach (array_chunk($subjectRows, 50) as $rows) {
            DB::table('wanikani_subjects')->upsert(
                $rows,
                ['subject_id'],
                [
                    'subject_type',
                    'characters',
                    'normalized_key',
                    'readings',
                    'meanings',
                    'hidden_at',
                    'source_updated_at',
                    'matcher_version',
                    'updated_at',
                ],
            );
        }
    }

    /** @param list<array<string, mixed>> $assignmentRows */
    private function upsertVocabularyAssignments(array $assignmentRows): void
    {
        foreach (array_chunk($assignmentRows, 50) as $rows) {
            DB::table('user_wanikani_assignments')->upsert(
                $rows,
                ['user_id', 'subject_id'],
                [
                    'srs_stage',
                    'passed_at',
                    'burned_at',
                    'hidden',
                    'source_updated_at',
                    'updated_at',
                ],
            );
        }
    }

    private function reconcileStaleVocabularyMatches(WaniKaniConnection $connection, CarbonImmutable $syncedAt): void
    {
        $subjects = DB::table('wanikani_subjects as subjects')
            ->join('user_wanikani_assignments as assignments', 'assignments.subject_id', '=', 'subjects.subject_id')
            ->where('assignments.user_id', $connection->user_id)
            ->where(function ($query): void {
                $query->whereNull('subjects.matcher_version')
                    ->orWhere('subjects.matcher_version', '!=', WaniKaniVocabularyConceptMatcher::VERSION);
            })
            ->orderBy('subjects.subject_id')
            ->lockForUpdate()
            ->get(['subjects.subject_id', 'subjects.characters', 'subjects.readings']);

        if ($subjects->isEmpty()) {
            return;
        }

        $storedSubjects = $subjects->map(function (object $subject): array {
            return [
                'subject_id' => (int) $subject->subject_id,
                'characters' => (string) $subject->characters,
                'readings' => $this->decodeStoredReadings($subject),
            ];
        })->all();
        $subjectIds = array_column($storedSubjects, 'subject_id');
        $matches = $this->vocabularyMatcher->matchSubjects($storedSubjects);

        $this->replaceVocabularyMatches($subjectIds, $matches, $syncedAt);
        DB::table('wanikani_subjects')
            ->whereIn('subject_id', $subjectIds)
            ->update([
                'matcher_version' => WaniKaniVocabularyConceptMatcher::VERSION,
                'updated_at' => $syncedAt,
            ]);
    }

    /** @return list<string> */
    private function decodeStoredReadings(object $subject): array
    {
        $readings = json_decode((string) $subject->readings, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($readings) || ! array_is_list($readings)) {
            throw new \UnexpectedValueException('Stored WaniKani readings must be a JSON list.');
        }
        if (array_filter($readings, static fn (mixed $reading): bool => ! is_string($reading)) !== []) {
            throw new \UnexpectedValueException('Stored WaniKani readings must be a JSON list.');
        }

        return $readings;
    }

    /**
     * @param  list<int>  $subjectIds
     * @param  list<array{subject_id: int, concept_id: string, match_method: string, confidence: float}>  $matches
     */
    private function replaceVocabularyMatches(array $subjectIds, array $matches, CarbonImmutable $syncedAt): void
    {
        DB::table('wanikani_subject_learning_concepts')
            ->whereIn('subject_id', $subjectIds)
            ->delete();

        $matchRows = array_map(fn (array $match): array => [
            ...$match,
            'matcher_version' => WaniKaniVocabularyConceptMatcher::VERSION,
            'created_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ], $matches);
        foreach (array_chunk($matchRows, 50) as $rows) {
            DB::table('wanikani_subject_learning_concepts')->insert($rows);
        }
    }
}
