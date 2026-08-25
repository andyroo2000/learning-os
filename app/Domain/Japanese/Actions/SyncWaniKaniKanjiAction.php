<?php

namespace App\Domain\Japanese\Actions;

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

final class SyncWaniKaniKanjiAction
{
    private const OVERLAP_MINUTES = 5;

    public function __construct(
        private readonly WaniKaniApiClient $client,
        private readonly WaniKaniVocabularyConceptMatcher $vocabularyMatcher,
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
            $connection = WaniKaniConnection::query()->where('user_id', $userId)->firstOrFail();
            $syncStartedAt = CarbonImmutable::now('UTC');
            $updatedAfter = $connection->assignments_synced_through_at?->subMinutes(self::OVERLAP_MINUTES);
            $vocabularyUpdatedAfter = $connection->vocabulary_assignments_synced_through_at?->subMinutes(self::OVERLAP_MINUTES);
            $passedKanji = $this->client->passedKanji((string) $connection->api_token, $updatedAfter);
            $vocabularyProgress = $this->client->vocabularyProgress(
                (string) $connection->api_token,
                $vocabularyUpdatedAfter,
            );
            $vocabularyMatches = $this->vocabularyMatcher->match($vocabularyProgress);
            $reviewCount = null;
            $reviewCountFetched = false;

            try {
                $reviewCount = $this->client->immediateReviewCount((string) $connection->api_token);
                $reviewCountFetched = true;
            } catch (WaniKaniApiException $exception) {
                Log::warning('WaniKani review count refresh failed; preserving the cached count.', [
                    'user_id' => $userId,
                    'status' => $exception->getCode(),
                ]);
            }

            return DB::transaction(function () use (
                $userId,
                $connection,
                $syncStartedAt,
                $passedKanji,
                $vocabularyProgress,
                $vocabularyMatches,
                $reviewCount,
                $reviewCountFetched,
            ): array {
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

                $vocabularyAdded = $this->persistVocabularyProgress(
                    $userId,
                    $vocabularyProgress,
                    $vocabularyMatches,
                    $syncStartedAt,
                );
                $this->reconcileStaleVocabularyMatches($userId, $syncStartedAt);

                if ($added > 0) {
                    $profile->increment('knowledge_version', $added);
                    $profile->refresh();
                }

                $lockedConnection = WaniKaniConnection::query()
                    ->whereKey($connection->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedConnection->assignments_synced_through_at = $syncStartedAt;
                $lockedConnection->vocabulary_assignments_synced_through_at = $syncStartedAt;
                $lockedConnection->last_synced_at = $syncStartedAt;
                if ($reviewCountFetched) {
                    $lockedConnection->review_count = $reviewCount;
                    $lockedConnection->review_count_updated_at = $syncStartedAt;
                }
                $lockedConnection->save();

                $effectiveTotal = UserKnownKanji::query()
                    ->where('user_id', $userId)
                    ->where(function ($query): void {
                        $query->whereNotNull('wanikani_passed_at')->orWhereNotNull('manually_added_at');
                    })
                    ->count();

                $vocabularyKnownTotal = DB::table('user_wanikani_assignments')
                    ->where('user_id', $userId)
                    ->whereNotNull('passed_at')
                    ->count();
                $vocabularyMatchedTotal = DB::table('user_wanikani_assignments as assignments')
                    ->join(
                        'wanikani_subject_learning_concepts as links',
                        'links.subject_id',
                        '=',
                        'assignments.subject_id',
                    )
                    ->where('assignments.user_id', $userId)
                    ->whereNotNull('assignments.passed_at')
                    ->distinct()
                    ->count('links.concept_id');

                return [
                    'added' => $added,
                    'effectiveTotal' => $effectiveTotal,
                    'version' => (int) $profile->knowledge_version,
                    'reviewCount' => $lockedConnection->review_count,
                    'vocabularyAdded' => $vocabularyAdded,
                    'vocabularyKnownTotal' => $vocabularyKnownTotal,
                    'vocabularyMatchedTotal' => $vocabularyMatchedTotal,
                ];
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<WaniKaniVocabularyProgress>  $progress
     * @param  list<array{subject_id: int, concept_id: string, match_method: string, confidence: float}>  $matches
     */
    private function persistVocabularyProgress(
        int $userId,
        array $progress,
        array $matches,
        CarbonImmutable $syncedAt,
    ): int {
        if ($progress === []) {
            return 0;
        }

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

        // Serialize global subject-to-concept rematches across users. The existing
        // per-user cache lock cannot prevent two accounts syncing the same subject.
        DB::table('wanikani_subjects')
            ->whereIn('subject_id', $subjectIds)
            ->orderBy('subject_id')
            ->lockForUpdate()
            ->get(['subject_id']);

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

        $this->replaceVocabularyMatches($subjectIds, $matches, $syncedAt);

        return $vocabularyAdded;
    }

    private function reconcileStaleVocabularyMatches(int $userId, CarbonImmutable $syncedAt): void
    {
        $subjects = DB::table('wanikani_subjects as subjects')
            ->join('user_wanikani_assignments as assignments', 'assignments.subject_id', '=', 'subjects.subject_id')
            ->where('assignments.user_id', $userId)
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

        $storedSubjects = $subjects->map(static function (object $subject): array {
            $readings = json_decode((string) $subject->readings, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($readings)
                || ! array_is_list($readings)
                || array_filter($readings, static fn (mixed $reading): bool => ! is_string($reading)) !== []
            ) {
                throw new \UnexpectedValueException('Stored WaniKani readings must be a JSON list.');
            }

            return [
                'subject_id' => (int) $subject->subject_id,
                'characters' => (string) $subject->characters,
                'readings' => $readings,
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
