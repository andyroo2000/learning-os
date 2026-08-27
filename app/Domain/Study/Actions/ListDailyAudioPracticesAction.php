<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Support\DailyAudioPracticeCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ListDailyAudioPracticesAction
{
    public const RECENT_LIMIT = 14;

    public const PAGE_SIZE_MAX = 50;

    /** @return Collection<int, DailyAudioPractice> */
    public function handle(int $userId): Collection
    {
        return $this->query($userId)
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * @return array{
     *     items: Collection<int, DailyAudioPractice>,
     *     total: int,
     *     limit: int,
     *     nextCursor: string|null
     * }
     */
    public function handlePage(int $userId, int|string|null $cursor, int $limit): array
    {
        if ($limit < 1 || $limit > self::PAGE_SIZE_MAX) {
            throw new InvalidArgumentException('Daily audio pagination is out of range.');
        }

        $legacyOffset = $this->legacyOffset($cursor);
        $boundary = $legacyOffset === null && $cursor !== null
            ? DailyAudioPracticeCursor::decode($cursor)
            : null;
        $total = DailyAudioPractice::query()
            ->where('user_id', $userId)
            ->count();
        $query = $this->query($userId);

        if ($legacyOffset !== null) {
            // Numeric offsets were emitted before keyset pagination. Accept one
            // legacy boundary, then return an opaque stable cursor.
            $query->offset($legacyOffset);
        } elseif ($boundary !== null) {
            $query->where(function (Builder $query) use ($boundary): void {
                $query->where('practice_date', '<', $boundary['practice_date'])
                    ->orWhere(function (Builder $query) use ($boundary): void {
                        $query->where('practice_date', $boundary['practice_date'])
                            ->where('id', '<', $boundary['id']);
                    });
            });
        }

        $page = $query->limit($limit + 1)->get();
        $hasMore = $page->count() > $limit;
        $items = $page->take($limit)->values();
        $lastItem = $items->last();

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'nextCursor' => $hasMore && $lastItem instanceof DailyAudioPractice
                ? DailyAudioPracticeCursor::encode($lastItem)
                : null,
        ];
    }

    private function legacyOffset(int|string|null $cursor): ?int
    {
        if ($cursor === null) {
            return null;
        }

        if (is_int($cursor)) {
            if ($cursor < 0) {
                throw new InvalidArgumentException('Daily audio pagination is out of range.');
            }

            return $cursor;
        }

        return DailyAudioPracticeCursor::decodeLegacyOffset($cursor);
    }

    /** @return Builder<DailyAudioPractice> */
    private function query(int $userId): Builder
    {
        // Convo Lab lists the full practice-level JSON; only the large per-track generation payloads are omitted.
        return DailyAudioPractice::query()
            ->where('user_id', $userId)
            ->with(['tracks' => fn ($query) => $query
                ->select([
                    'id',
                    'practice_id',
                    'mode',
                    'status',
                    'title',
                    'sort_order',
                    'audio_url',
                    'approx_duration_seconds',
                    'error_message',
                    'created_at',
                    'updated_at',
                ])
                ->orderBy('sort_order')
                ->orderBy('id')])
            ->orderByDesc('practice_date')
            ->orderByDesc('id');
    }
}
