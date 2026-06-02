<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Models\MediaAsset;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListMediaAssetsAction
{
    /**
     * @return CursorPaginator<MediaAsset>
     */
    public function handle(int $userId, ?CursorPageSize $pageSize = null): CursorPaginator
    {
        $pageSize ??= CursorPageSize::fromMaxPageSize();

        return MediaAsset::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($pageSize->value());
    }
}
