<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportPreviewException;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportArchiveImporter;
use App\Domain\Study\Support\StudyImportArchivePreviewer;
use App\Domain\Study\Support\StudyImportArchiveReader;
use App\Domain\Study\Support\StudyImportJobFailureMarker;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
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

        if (! Str::isUlid($importJobId)) {
            return null;
        }

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
                return StudyImportJobFailureMarker::markFailed($importJob, 'Study import upload target is missing.', $now);
            }

            if ($importJob->upload_completed_at === null) {
                return StudyImportJobFailureMarker::markFailed($importJob, 'Study import upload has not been completed.', $now);
            }

            if (! Storage::disk('study-imports')->exists($importJob->source_object_path)) {
                return StudyImportJobFailureMarker::markFailed($importJob, 'Study import archive is missing.', $now);
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

        $snapshot = null;

        try {
            try {
                $snapshot = $this->archiveReader->snapshot(
                    Storage::disk('study-imports'),
                    (string) $importJob->source_object_path,
                );
                $archive = $this->archiveReader->readSnapshot($snapshot);
            } catch (StudyImportPreviewException $exception) {
                if ($exception->getPrevious() !== null) {
                    report($exception);
                }

                return StudyImportJobFailureMarker::markFailed($importJob, $exception->getMessage(), $now);
            } catch (Throwable $exception) {
                report($exception);

                return StudyImportJobFailureMarker::markFailed(
                    $importJob,
                    StudyImportPreviewException::invalidCollectionDatabase()->getMessage(),
                    $now,
                );
            }

            try {
                $preview = $this->archivePreviewer->previewArchive($archive);
            } catch (Throwable $exception) {
                report($exception);

                return StudyImportJobFailureMarker::markFailed(
                    $this->freshImportJob($importJob),
                    'Study import preview could not be prepared.',
                    $now,
                );
            }

            try {
                $completedImportJob = $this->archiveImporter->import($importJob, $archive, $snapshot, $preview, $now);
            } catch (Throwable $exception) {
                report($exception);

                return StudyImportJobFailureMarker::markFailed(
                    $this->freshImportJob($importJob),
                    'Study import could not be processed.',
                    $now,
                );
            }

            $this->deleteCompletedSourceArchive($completedImportJob);

            return $completedImportJob;
        } finally {
            $snapshot?->close();
        }
    }

    private function deleteCompletedSourceArchive(StudyImportJob $importJob): void
    {
        $sourceObjectPath = $importJob->source_object_path;

        if (! is_string($sourceObjectPath) || $sourceObjectPath === '') {
            return;
        }

        try {
            if (Storage::disk('study-imports')->delete($sourceObjectPath)) {
                return;
            }

            report(new RuntimeException(
                'Unable to delete a completed study import source archive: '.$sourceObjectPath,
            ));
        } catch (Throwable $exception) {
            report(new RuntimeException(
                'Unable to delete a completed study import source archive: '.$sourceObjectPath,
                previous: $exception,
            ));
        }
    }

    private function freshImportJob(StudyImportJob $importJob): StudyImportJob
    {
        return StudyImportJob::query()->find($importJob->id) ?? $importJob;
    }
}
