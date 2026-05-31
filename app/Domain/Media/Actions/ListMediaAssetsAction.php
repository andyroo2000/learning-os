<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Models\MediaAsset;
use App\Support\Pagination\CursorPagination;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListMediaAssetsAction
{
    /**
     * @return CursorPaginator<MediaAsset>
     */
    public function handle(int $userId, int $perPage = CursorPagination::MAX_PAGE_SIZE): CursorPaginator
    {
        $perPage = CursorPagination::clampPageSize($perPage);

        return MediaAsset::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }
}
