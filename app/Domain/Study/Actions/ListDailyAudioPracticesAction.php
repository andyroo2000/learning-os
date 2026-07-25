<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\DailyAudioPractice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
    public function handlePage(int $userId, int $cursor, int $limit): array
    {
        if ($cursor < 0 || $limit < 1 || $limit > self::PAGE_SIZE_MAX) {
            throw new \InvalidArgumentException('Daily audio pagination is out of range.');
        }

        $total = DailyAudioPractice::query()
            ->where('user_id', $userId)
            ->count();
        $items = $this->query($userId)
            ->offset($cursor)
            ->limit($limit)
            ->get();
        $nextOffset = $cursor + $items->count();

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'nextCursor' => $nextOffset < $total ? (string) $nextOffset : null,
        ];
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
