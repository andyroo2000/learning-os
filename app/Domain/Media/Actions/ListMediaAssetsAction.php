<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListMediaAssetsAction
{
    private const PAGE_SIZE = 50;

    /**
     * @return CursorPaginator<MediaAsset>
     */
    public function handle(int $userId): CursorPaginator
    {
        return MediaAsset::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PAGE_SIZE);
    }
}
