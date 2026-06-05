<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\CardSearchText;
use App\Support\Identifiers\CanonicalUlid;
use App\Support\Pagination\CursorPageSize;
use Illuminate\Contracts\Pagination\CursorPaginator;
use InvalidArgumentException;

class ListCardsAction
{
    /**
     * @return CursorPaginator<Card>
     */
    public function handle(
        int $userId,
        ?CursorPageSize $pageSize = null,
        ?string $courseId = null,
        CardStudyStatus|string|null $studyStatus = null,
        ?string $q = null,
    ): CursorPaginator {
        $pageSize ??= CursorPageSize::fromDefaultPageSize();
        $courseId = $courseId === null ? null : CanonicalUlid::normalize($courseId);
        $studyStatus = $studyStatus === null ? null : CardStudyStatus::fromFilter($studyStatus);
        $searchPattern = $q === null ? null : CardSearchText::likePattern($q);

        if ($courseId === '') {
            throw new InvalidArgumentException('Card course_id filter must not be blank when provided.');
        }

        return Card::query()
            ->with(['deck:id,user_id,course_id'])
            ->whereHas('deck', fn ($query) => $query
                ->where('user_id', $userId)
                ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId)))
            ->when($studyStatus !== null, fn ($query) => $query->where('study_status', $studyStatus->value))
            ->when($searchPattern !== null, fn ($query) => $query->whereRaw(
                "lower(coalesce(cards.search_text, '')) like ? escape ?",
                [$searchPattern, '\\'],
            ))
            ->orderByDesc('created_at')
            // id desc is stable for cursor pagination; same-millisecond ULID order is arbitrary.
            ->orderByDesc('id')
            ->cursorPaginate($pageSize->value());
    }
}
