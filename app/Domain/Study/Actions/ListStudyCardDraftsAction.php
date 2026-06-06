<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardDraftCursor;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ListStudyCardDraftsAction
{
    public const DEFAULT_LIMIT = 200;

    public const MAX_LIMIT = 2000;

    /**
     * @return array{drafts: Collection<int, StudyCardDraft>, total: int|null, limit: int, nextCursor: string|null}
     */
    public function handle(
        int $userId,
        ?string $cursor = null,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('limit must be an integer between 1 and '.self::MAX_LIMIT.'.');
        }

        $decodedCursor = StudyCardDraftCursor::decode($cursor);
        $baseQuery = StudyCardDraft::query()->where('user_id', $userId);
        // First-page loads report progress; cursor pages skip the extra count because callers already have it.
        $total = $decodedCursor === null ? (clone $baseQuery)->count() : null;

        $drafts = $baseQuery
            ->when($decodedCursor !== null, fn ($query) => $query->where(function ($query) use ($decodedCursor): void {
                $query
                    ->where('created_at', '>', $decodedCursor['created_at'])
                    ->orWhere(function ($query) use ($decodedCursor): void {
                        $query
                            ->where('created_at', $decodedCursor['created_at'])
                            ->where('id', '>', $decodedCursor['id']);
                    });
            }))
            ->orderBy('created_at')
            ->orderBy('id')
            ->take($limit + 1)
            ->get();

        $hasMore = $drafts->count() > $limit;
        $page = $drafts->take($limit)->values();

        return [
            'drafts' => $page,
            'total' => $total,
            'limit' => $limit,
            'nextCursor' => $hasMore ? StudyCardDraftCursor::encode($page->last()) : null,
        ];
    }
}
