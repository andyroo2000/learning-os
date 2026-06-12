<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

class PrepareStudyImportActiveSlotAction
{
    public function __construct(
        private readonly ExpireStaleProcessingStudyImportsAction $expireStaleProcessingStudyImports,
    ) {}

    public function handle(
        int $userId,
        Carbon $now,
        ?string $excludedImportJobId = null,
    ): ?StudyImportJob {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Study import active-slot preparation must run inside a database transaction.');
        }

        $this->lockUser($userId);
        $this->expireStalePendingImports($userId, $now, $excludedImportJobId);
        $this->expireStaleProcessingStudyImports->handle($userId, $now);

        return $this->activeImport($userId, $excludedImportJobId);
    }

    private function lockUser(int $userId): void
    {
        User::query()
            ->whereKey($userId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function expireStalePendingImports(int $userId, Carbon $now, ?string $excludedImportJobId): void
    {
        StudyImportJob::query()
            ->where('user_id', $userId)
            ->where('status', StudyImportStatus::Pending->value)
            ->when($excludedImportJobId !== null, fn ($query) => $query->whereKeyNot($excludedImportJobId))
            ->where(function ($query) use ($now): void {
                $query
                    ->whereNull('upload_expires_at')
                    ->orWhere('upload_expires_at', '<', $now);
            })
            ->update([
                'status' => StudyImportStatus::Failed->value,
                'error_message' => 'Study import upload session has expired.',
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function activeImport(int $userId, ?string $excludedImportJobId): ?StudyImportJob
    {
        return StudyImportJob::query()
            ->where('user_id', $userId)
            ->active()
            ->when($excludedImportJobId !== null, fn ($query) => $query->whereKeyNot($excludedImportJobId))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }
}
