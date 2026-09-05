<?php

namespace Tests\Support\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportUploadPath;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

trait BuildsTerminalStudyImportArchives
{
    private function completedImportJob(
        string $filename,
        Carbon $completedAt,
        bool $canonical = true,
    ): StudyImportJob {
        return $this->terminalImportJob(StudyImportStatus::Completed, $filename, $completedAt, $canonical);
    }

    private function terminalImportJob(
        StudyImportStatus $status,
        string $filename,
        Carbon $completedAt,
        bool $canonical = true,
    ): StudyImportJob {
        $importJob = StudyImportJob::factory()->create([
            'status' => $status,
            'completed_at' => $completedAt,
            'source_object_path' => null,
        ]);
        $importJob->source_object_path = $canonical
            ? StudyImportUploadPath::forImportJob($importJob->user_id, $importJob->id, $filename)
            : StudyImportJob::SOURCE_UPLOAD_FOLDER.'/unsafe/'.$filename;
        $importJob->saveOrFail();

        return $importJob;
    }

    /** @param array<string, mixed> $attributes */
    private function activeImportJob(
        User $user,
        StudyImportStatus $status,
        string $filename,
        array $attributes = [],
    ): StudyImportJob {
        $importJob = StudyImportJob::factory()->for($user)->create([
            'status' => $status,
            'source_object_path' => null,
            ...$attributes,
        ]);
        $importJob->source_object_path = StudyImportUploadPath::forImportJob(
            $user->id,
            $importJob->id,
            $filename,
        );
        $importJob->saveOrFail();
        Storage::disk('study-imports')->put($importJob->source_object_path, 'not a zip archive');

        return $importJob;
    }
}
