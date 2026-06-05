<?php

namespace App\Domain\Study\Actions;

class GetStudyImportUploadReadinessAction
{
    /**
     * @return array{ready: bool, message: string|null}
     */
    public function handle(): array
    {
        $disk = config('filesystems.disks.study-imports');

        if (is_array($disk)) {
            return [
                'ready' => true,
                'message' => null,
            ];
        }

        return [
            'ready' => false,
            'message' => 'Study import uploads are temporarily unavailable because storage is not configured.',
        ];
    }
}
