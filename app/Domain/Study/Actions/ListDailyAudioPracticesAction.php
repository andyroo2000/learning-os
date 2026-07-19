<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\DailyAudioPractice;
use Illuminate\Support\Collection;

class ListDailyAudioPracticesAction
{
    public const RECENT_LIMIT = 14;

    /** @return Collection<int, DailyAudioPractice> */
    public function handle(int $userId): Collection
    {
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
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }
}
