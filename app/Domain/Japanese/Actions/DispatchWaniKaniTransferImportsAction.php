<?php

namespace App\Domain\Japanese\Actions;

use App\Domain\Japanese\Models\WaniKaniConnection;
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
use Carbon\CarbonInterface;
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

    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly CreateStudyVocabBundleDraftsAction $createBundleDrafts,
        private readonly RetryStudyVocabBundleDraftsAction $retryBundleDrafts,
        private readonly FailStudyVocabBundleDraftsAction $failBundleDrafts,
        private readonly MarkAutomaticStudyVocabImportFailedAction $markImportFailed,
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
            $connection = WaniKaniConnection::query()
                ->where('user_id', $userId)
                ->where('transfer_bridge_enabled', true)
                ->first();
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

            $candidates = $this->candidates(
                $userId,
                $connection->transfer_bridge_enabled_at->subDay(),
                $remaining,
            );
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

    /** @return Collection<int, object> */
    private function candidates(int $userId, CarbonInterface $passedAfter, int $limit): Collection
    {
        return DB::table('user_wanikani_assignments as assignments')
            ->join('wanikani_subjects as subjects', 'subjects.subject_id', '=', 'assignments.subject_id')
            ->leftJoin('study_vocab_variant_groups as groups', function ($join) use ($userId): void {
                $join->on('groups.wanikani_subject_id', '=', 'assignments.subject_id')
                    ->where('groups.user_id', '=', $userId);
            })
            ->where('assignments.user_id', $userId)
            ->whereNotNull('assignments.passed_at')
            ->where('assignments.passed_at', '>=', $passedAfter)
            ->where('assignments.hidden', false)
            ->whereNull('subjects.hidden_at')
            ->whereIn('subjects.subject_type', ['vocabulary', 'kana_vocabulary'])
            ->whereNull('groups.id')
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
