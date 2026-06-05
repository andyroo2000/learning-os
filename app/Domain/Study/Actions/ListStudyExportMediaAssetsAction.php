<?php

namespace App\Domain\Study\Actions;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\Collection;

class ListStudyExportMediaAssetsAction
{
    /**
     * @return Collection<int, MediaAsset>
     */
    public function handle(int $userId): Collection
    {
        return MediaAsset::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            // Unbounded by design: clients use this complete section during full export/resync.
            ->get();
    }
}
