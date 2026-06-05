<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportPreviewException;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportArchivePreviewer;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessStudyImportJobAction
{
    public function __construct(
        private readonly StudyImportArchivePreviewer $archivePreviewer,
    ) {}

    public function handle(string $importJobId, ?Carbon $now = null): ?StudyImportJob
    {
        $now ??= now();
        $importJobId = CanonicalUlid::normalize($importJobId);
        $claimedForProcessing = false;

        $importJob = DB::transaction(function () use ($importJobId, $now, &$claimedForProcessing): ?StudyImportJob {
            $importJob = StudyImportJob::query()
                ->whereKey($importJobId)
                ->lockForUpdate()
                ->first();

            if ($importJob === null) {
                return null;
            }

            if ($importJob->status === StudyImportStatus::Processing
                || $importJob->status === StudyImportStatus::Completed
                || $importJob->status === StudyImportStatus::Failed) {
                return $importJob;
            }

            if ($importJob->source_object_path === null || $importJob->source_object_path === '') {
                return $this->markFailed($importJob, 'Study import upload target is missing.', $now);
            }

            if (! Storage::disk('study-imports')->exists($importJob->source_object_path)) {
                return $this->markFailed($importJob, 'Study import archive is missing.', $now);
            }

            $importJob->status = StudyImportStatus::Processing;
            $importJob->started_at ??= $now;
            $importJob->error_message = null;
            $importJob->completed_at = null;
            $importJob->saveOrFail();
            $claimedForProcessing = true;

            return $importJob;
        });

        if (! $claimedForProcessing || $importJob === null) {
            return $importJob;
        }

        try {
            $preview = $this->archivePreviewer->preview(
                Storage::disk('study-imports'),
                (string) $importJob->source_object_path,
            );
        } catch (StudyImportPreviewException $exception) {
            return $this->markFailed($importJob, $exception->getMessage(), $now);
        } catch (Throwable) {
            return $this->markFailed($importJob, StudyImportPreviewException::invalidCollectionDatabase()->getMessage(), $now);
        }

        $importJob->deck_name = is_string($preview['deck_name'] ?? null)
            ? $preview['deck_name']
            : StudyImportJob::DEFAULT_DECK_NAME;
        $importJob->preview_json = $preview;
        $importJob->saveOrFail();

        return $importJob;
    }

    private function markFailed(StudyImportJob $importJob, string $message, Carbon $now): StudyImportJob
    {
        $importJob->status = StudyImportStatus::Failed;
        $importJob->error_message = $message;
        $importJob->completed_at = $now;
        $importJob->saveOrFail();

        return $importJob;
    }
}
