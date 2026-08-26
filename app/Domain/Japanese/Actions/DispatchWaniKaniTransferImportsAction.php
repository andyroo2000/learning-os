<?php

namespace App\Domain\Japanese\Actions;

use App\Domain\Japanese\Models\WaniKaniConnection;
use App\Domain\Japanese\Queries\WaniKaniTransferEligibleAssignmentsQuery;
use App\Domain\Study\Actions\CreateStudyVocabBundleDraftsAction;
use App\Domain\Study\Actions\FailStudyVocabBundleDraftsAction;
use App\Domain\Study\Actions\MarkAutomaticStudyVocabImportFailedAction;
use App\Domain\Study\Actions\RetryStudyVocabBundleDraftsAction;
use App\Domain\Study\Data\CreateStudyVocabBundleData;
use App\Domain\Study\Enums\AutomaticStudyVocabImportStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Models\StudyVocabVariantGroup;
use App\Jobs\ProcessStudyVocabBundleDrafts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;

final class DispatchWaniKaniTransferImportsAction
{
    public const DAILY_NEW_IMPORT_LIMIT = 2;

    public const FAILED_RETRY_LIMIT_PER_DISPATCH = 2;

    public const INITIAL_SEED_LIMIT = 10;

    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly CreateStudyVocabBundleDraftsAction $createBundleDrafts,
        private readonly RetryStudyVocabBundleDraftsAction $retryBundleDrafts,
        private readonly FailStudyVocabBundleDraftsAction $failBundleDrafts,
        private readonly MarkAutomaticStudyVocabImportFailedAction $markImportFailed,
        private readonly WaniKaniTransferEligibleAssignmentsQuery $eligibleAssignments,
    ) {}

    /** @return array{created: int, retried: int} */
    public function handle(int $userId): array
    {
        if ($userId < 1) {
            throw new LogicException('WaniKani transfer user ID must be a positive integer.');
        }

        $lock = Cache::lock("wanikani-transfer:user:{$userId}", self::LOCK_SECONDS);
        if (! $lock->get()) {
            return ['created' => 0, 'retried' => 0];
        }

        try {
            $connection = $this->prepareQueue($userId);
            if ($connection === null || $connection->transfer_bridge_enabled_at === null) {
                return ['created' => 0, 'retried' => 0];
            }

            $retried = $this->retryFailedImports($userId);
            $createdToday = StudyVocabVariantGroup::query()
                ->where('user_id', $userId)
                ->whereNotNull('wanikani_subject_id')
                ->where('created_at', '>=', CarbonImmutable::now('UTC')->startOfDay())
                ->count();
            $remaining = max(0, self::DAILY_NEW_IMPORT_LIMIT - $createdToday);
            if ($remaining === 0) {
                return ['created' => 0, 'retried' => $retried];
            }

            $candidates = $this->candidates($userId, $remaining);
            $created = 0;

            foreach ($candidates as $candidate) {
                try {
                    $this->createBundleDrafts->handle(
                        CreateStudyVocabBundleData::fromInput(
                            userId: $userId,
                            targetWord: (string) $candidate->characters,
                            sourceSentence: null,
                            context: $this->candidateContext($candidate),
                            includeLearnerContext: true,
                            waniKaniSubjectId: (int) $candidate->subject_id,
                        ),
                        static fn (string $groupId) => ProcessStudyVocabBundleDrafts::dispatch($groupId),
                    );
                    $created++;
                } catch (Throwable $exception) {
                    report($exception);
                    $group = StudyVocabVariantGroup::query()
                        ->where('user_id', $userId)
                        ->where('wanikani_subject_id', (int) $candidate->subject_id)
                        ->first();
                    if ($group !== null) {
                        $this->markFailed($group, 'Could not queue this automatic vocabulary import.');
                    }
                }
            }

            return ['created' => $created, 'retried' => $retried];
        } finally {
            $lock->release();
        }
    }

    private function retryFailedImports(int $userId): int
    {
        $groups = StudyVocabVariantGroup::query()
            ->where('user_id', $userId)
            ->whereNotNull('wanikani_subject_id')
            ->where('automatic_import_status', AutomaticStudyVocabImportStatus::Error->value)
            ->oldest()
            ->limit(self::FAILED_RETRY_LIMIT_PER_DISPATCH)
            ->get();
        $retried = 0;

        foreach ($groups as $group) {
            try {
                $group->automatic_import_status = AutomaticStudyVocabImportStatus::Generating;
                $group->automatic_import_error = null;
                $group->save();

                if ($group->target_reading === null) {
                    $draft = StudyCardDraft::query()
                        ->where('user_id', $userId)
                        ->where('variant_group_id', $group->id)
                        ->oldest()
                        ->first();
                    if ($draft === null) {
                        throw new RuntimeException('Automatic study vocab bundle has no drafts to retry.');
                    }

                    $retriedDraft = $this->retryBundleDrafts->handleIfBundle(
                        $userId,
                        $draft->id,
                        static fn (string $groupId) => ProcessStudyVocabBundleDrafts::dispatch($groupId),
                    );
                    if ($retriedDraft === null) {
                        throw new RuntimeException('Automatic study vocab bundle could not be retried.');
                    }
                } else {
                    ProcessStudyVocabBundleDrafts::dispatch($group->id);
                }
                $retried++;
            } catch (Throwable $exception) {
                report($exception);
                $this->markFailed($group, 'Could not retry this automatic vocabulary import.');
            }
        }

        return $retried;
    }

    private function prepareQueue(int $userId): ?WaniKaniConnection
    {
        return DB::transaction(function () use ($userId): ?WaniKaniConnection {
            $connection = WaniKaniConnection::query()
                ->where('user_id', $userId)
                ->where('transfer_bridge_enabled', true)
                ->lockForUpdate()
                ->first();
            if ($connection === null || $connection->transfer_bridge_enabled_at === null) {
                return null;
            }

            if ($connection->transfer_bridge_seeded_at === null) {
                $seedIds = $this->eligibleAssignmentIds($userId)
                    ->orderByDesc('assignments.passed_at')
                    ->orderByDesc('assignments.subject_id')
                    ->limit(self::INITIAL_SEED_LIMIT)
                    ->pluck('assignments.subject_id');

                if ($seedIds->isNotEmpty()) {
                    DB::table('user_wanikani_assignments')
                        ->where('user_id', $userId)
                        ->whereIn('subject_id', $seedIds)
                        ->whereNull('transfer_bridge_queued_at')
                        ->update(['transfer_bridge_queued_at' => now()]);

                    $connection->transfer_bridge_seeded_at = now();
                    $connection->save();
                }
            }

            if ($connection->transfer_bridge_seeded_at !== null) {
                // assignments.created_at uses whole-second precision, while the seed marker
                // preserves microseconds. Floor only the first-observed comparison so a row
                // synced later in the same second cannot fall through the durable boundary.
                $observedCutoff = $connection->transfer_bridge_seeded_at->startOfSecond();
                $futureIds = $this->eligibleAssignmentIds($userId)
                    ->where(function ($query) use ($connection, $observedCutoff): void {
                        $query->where('assignments.passed_at', '>=', $connection->transfer_bridge_seeded_at)
                            ->orWhere('assignments.created_at', '>=', $observedCutoff);
                    })
                    ->pluck('assignments.subject_id');
                if ($futureIds->isNotEmpty()) {
                    DB::table('user_wanikani_assignments')
                        ->where('user_id', $userId)
                        ->whereIn('subject_id', $futureIds)
                        ->whereNull('transfer_bridge_queued_at')
                        ->update(['transfer_bridge_queued_at' => now()]);
                }
            }

            return $connection;
        });
    }

    private function eligibleAssignmentIds(int $userId): Builder
    {
        return $this->eligibleAssignments->forUser($userId)
            ->whereNull('assignments.transfer_bridge_queued_at');
    }

    /** @return Collection<int, object> */
    private function candidates(int $userId, int $limit): Collection
    {
        return $this->eligibleAssignments->forUser($userId)
            ->whereNotNull('assignments.transfer_bridge_queued_at')
            ->orderBy('assignments.passed_at')
            ->orderBy('assignments.subject_id')
            ->limit($limit)
            ->get([
                'assignments.subject_id',
                'assignments.passed_at',
                'subjects.characters',
                'subjects.readings',
                'subjects.meanings',
            ]);
    }

    private function candidateContext(object $candidate): string
    {
        $readings = $this->stringList((string) $candidate->readings);
        $meanings = $this->stringList((string) $candidate->meanings);
        $context = sprintf(
            'WaniKani reading%s: %s. Meaning%s: %s. Passed on WaniKani at %s.',
            count($readings) === 1 ? '' : 's',
            implode(', ', $readings),
            count($meanings) === 1 ? '' : 's',
            implode(', ', $meanings),
            CarbonImmutable::parse((string) $candidate->passed_at, 'UTC')->toIso8601String(),
        );

        return mb_substr($context, 0, CreateStudyVocabBundleData::MAX_CONTEXT_LENGTH);
    }

    /** @return list<string> */
    private function stringList(string $json): array
    {
        $values = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($values)
            || ! array_is_list($values)
            || array_filter($values, static fn (mixed $value): bool => ! is_string($value)) !== []) {
            throw new RuntimeException('Stored WaniKani vocabulary metadata is invalid.');
        }

        return array_values(array_filter(array_map('trim', $values), static fn (string $value): bool => $value !== ''));
    }

    private function markFailed(StudyVocabVariantGroup $group, string $message): void
    {
        try {
            $this->failBundleDrafts->handle($group->id, $message);
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            $this->markImportFailed->handle($group->id, $message);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
