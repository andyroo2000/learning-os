<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Results\ContentGenerationRequestPruneResult;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PruneTerminalContentGenerationRequestsAction
{
    public const DEFAULT_LIMIT = 500;

    public function handle(
        bool $dryRun = false,
        ?Carbon $now = null,
        int $limit = self::DEFAULT_LIMIT,
    ): ContentGenerationRequestPruneResult {
        $cutoff = ($now ?? now())->copy()->subDays(
            ContentGenerationRequest::TERMINAL_RETENTION_DAYS,
        );
        $candidateIds = $this->eligibleQuery($cutoff)
            ->orderBy('finished_at')
            ->orderBy('id')
            ->limit(min(5000, max(1, $limit)))
            ->pluck('id');

        if ($dryRun || $candidateIds->isEmpty()) {
            return new ContentGenerationRequestPruneResult(
                candidates: $candidateIds->count(),
                deleted: 0,
                skipped: 0,
                failed: 0,
                dryRun: $dryRun,
            );
        }

        try {
            $deleted = DB::transaction(function () use ($candidateIds, $cutoff): int {
                // Match every ledger writer's global-lock-then-row-lock ordering. The
                // bounded candidate set is rechecked after locking before deletion.
                ContentSourceLock::acquireConvoLab(DB::connection());
                $lockedIds = $this->eligibleQuery($cutoff)
                    ->whereKey($candidateIds->all())
                    ->orderBy('finished_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id');

                if ($lockedIds->isEmpty()) {
                    return 0;
                }

                return ContentGenerationRequest::query()
                    ->whereKey($lockedIds->all())
                    ->delete();
            });
        } catch (Throwable $exception) {
            report($exception);

            return new ContentGenerationRequestPruneResult(
                candidates: $candidateIds->count(),
                deleted: 0,
                skipped: 0,
                failed: $candidateIds->count(),
                dryRun: false,
            );
        }

        return new ContentGenerationRequestPruneResult(
            candidates: $candidateIds->count(),
            deleted: $deleted,
            skipped: $candidateIds->count() - $deleted,
            failed: 0,
            dryRun: false,
        );
    }

    /** @return Builder<ContentGenerationRequest> */
    private function eligibleQuery(Carbon $cutoff): Builder
    {
        return ContentGenerationRequest::query()
            ->whereIn('state', [
                ContentGenerationRequestState::COMPLETED,
                ContentGenerationRequestState::FAILED,
            ])
            ->whereNotNull('finished_at')
            ->where('finished_at', '<=', $cutoff);
    }
}
