<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudyImportJob;
use Illuminate\Support\Carbon;

class GetCurrentStudyImportJobAction
{
    public function __construct(
        private readonly ExpireStaleProcessingStudyImportsAction $expireStaleProcessingStudyImports,
    ) {}

    public function handle(int $userId, ?Carbon $now = null): ?StudyImportJob
    {
        $now ??= now();

        $this->expireStaleProcessingStudyImports->handle($userId, $now);

        return StudyImportJob::query()
            ->where('user_id', $userId)
            ->active()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }
}
