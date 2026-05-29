<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListMediaAssetsAction
{
    private const DEFAULT_PAGE_SIZE = 50;

    private const MAX_PAGE_SIZE = 50;

    /**
     * @return CursorPaginator<MediaAsset>
     */
    public function handle(int $userId, int $perPage = self::DEFAULT_PAGE_SIZE): CursorPaginator
    {
        $perPage = min(max($perPage, 1), self::MAX_PAGE_SIZE);

        return MediaAsset::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }
}
