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
        $importJobId = trim($importJobId);
        $query = StudyImportJob::query()->where('user_id', $userId);

        if (Str::isUlid($importJobId)) {
            $query->whereKey(CanonicalUlid::normalize($importJobId));
        } elseif (Str::isUuid($importJobId)) {
            $query->where('convolab_id', strtolower($importJobId));
        } else {
            throw (new ModelNotFoundException)->setModel(StudyImportJob::class);
        }

        return $query->first()
            ?? throw (new ModelNotFoundException)->setModel(StudyImportJob::class, [$importJobId]);
    }
}
