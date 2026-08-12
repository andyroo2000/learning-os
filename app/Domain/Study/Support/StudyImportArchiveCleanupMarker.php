<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Models\StudyImportJob;
use Illuminate\Support\Carbon;
use Throwable;

final class StudyImportArchiveCleanupMarker
{
    /**
     * Record successful archive cleanup without changing terminal-import sync ordering.
     */
    public static function markResolved(StudyImportJob $importJob, Carbon $now): StudyImportJob
    {
        $attributesBeforeMutation = $importJob->getAttributes();
        $timestamps = $importJob->timestamps;
        $importJob->timestamps = false;

        try {
            $importJob->archive_cleanup_attempted_at = $now;
            $importJob->archive_cleanup_resolved_at = $now;
            $importJob->archive_cleanup_claim_token = null;
            $importJob->archive_cleanup_error = null;
            $importJob->saveOrFail();
        } catch (Throwable $exception) {
            // A failed marker write must not make the returned model look resolved when persistence is not.
            $importJob->setRawAttributes($attributesBeforeMutation, sync: true);

            throw $exception;
        } finally {
            $importJob->timestamps = $timestamps;
        }

        return $importJob;
    }
}
