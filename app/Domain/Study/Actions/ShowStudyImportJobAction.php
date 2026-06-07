<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudyImportJob;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class ShowStudyImportJobAction
{
    public function handle(int $userId, string $importJobId): StudyImportJob
    {
        $importJobId = CanonicalUlid::normalize($importJobId);

        if (! Str::isUlid($importJobId)) {
            throw (new ModelNotFoundException)->setModel(StudyImportJob::class);
        }

        return StudyImportJob::query()
            ->where('user_id', $userId)
            ->whereKey($importJobId)
            ->first()
            ?? throw (new ModelNotFoundException)->setModel(StudyImportJob::class, [$importJobId]);
    }
}
