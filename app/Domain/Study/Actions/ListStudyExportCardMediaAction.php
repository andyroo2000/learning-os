<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Queries\StudyExportCardMediaQuery;
use Illuminate\Support\Collection;

class ListStudyExportCardMediaAction
{
    public function __construct(private readonly StudyExportCardMediaQuery $cardMediaQuery) {}

    /**
     * @return Collection<int, object{card_id: string, media_asset_id: string, created_at: mixed, updated_at: mixed}>
     */
    public function handle(int $userId): Collection
    {
        // Unbounded by design: clients use this complete section during full export/resync.
        return $this->cardMediaQuery->get($userId);
    }
}
