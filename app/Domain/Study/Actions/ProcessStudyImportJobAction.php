<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportPreviewException;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportArchiveImporter;
use App\Domain\Study\Support\StudyImportArchivePreviewer;
use App\Domain\Study\Support\StudyImportArchiveReader;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessStudyImportJobAction
{
    public function __construct(
        private readonly StudyImportArchiveReader $archiveReader,
        private readonly StudyImportArchivePreviewer $archivePreviewer,
        private readonly StudyImportArchiveImporter $archiveImporter,
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
            $archive = $this->archiveReader->read(
                Storage::disk('study-imports'),
                (string) $importJob->source_object_path,
            );
        } catch (StudyImportPreviewException $exception) {
            return $this->markFailed($importJob, $exception->getMessage(), $now);
        } catch (Throwable) {
            return $this->markFailed($importJob, StudyImportPreviewException::invalidCollectionDatabase()->getMessage(), $now);
        }

        try {
            $preview = $this->archivePreviewer->previewArchive($archive);
        } catch (Throwable $exception) {
            report($exception);

            return $this->markFailed($this->freshImportJob($importJob), 'Study import preview could not be prepared.', $now);
        }

        try {
            return $this->archiveImporter->import($importJob, $archive, $preview, $now);
        } catch (Throwable $exception) {
            report($exception);

            return $this->markFailed($this->freshImportJob($importJob), 'Study import could not be processed.', $now);
        }
    }

    private function freshImportJob(StudyImportJob $importJob): StudyImportJob
    {
        return StudyImportJob::query()->find($importJob->id) ?? $importJob;
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
