<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Models\MediaAsset;
use App\Support\Identifiers\CanonicalUlid;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;
use InvalidArgumentException;

class ListMediaAssetsAction
{
    /**
     * @return CursorPaginator<MediaAsset>
     */
    public function handle(int $userId, ?CursorPageSize $pageSize = null, ?string $courseId = null): CursorPaginator
    {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();
        $courseId = $courseId === null ? null : CanonicalUlid::normalize($courseId);

        if ($courseId === '') {
            throw new InvalidArgumentException('Media asset course_id filter must not be blank when provided.');
        }

        return MediaAsset::query()
            ->where('user_id', $userId)
            ->when($courseId !== null, fn ($query) => $query->whereHas('cards.deck', fn ($query) => $query
                ->where('user_id', $userId)
                ->where('course_id', $courseId)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($pageSize->value());
    }
}
