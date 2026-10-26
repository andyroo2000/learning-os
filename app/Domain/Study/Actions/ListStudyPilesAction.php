<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudyPile;
use App\Domain\Study\Sync\StudyPilePayload;
use Illuminate\Support\Facades\Validator;

final class ListStudyPilesAction
{
    public function handle(int $userId, int $cursor = 0, int $limit = 50): array
    {
        Validator::make(compact('cursor', 'limit'), [
            'cursor' => ['integer', 'min:0'],
            'limit' => ['integer', 'min:1', 'max:100'],
        ])->validate();
        $query = StudyPile::query()->where('user_id', $userId);
        $total = (clone $query)->count();
        $piles = $query->with('chapters')->orderBy('position')->orderBy('id')->skip($cursor)->take($limit)->get();
        $next = $cursor + $piles->count();

        return [
            'piles' => $piles->map(StudyPilePayload::withChapters(...))->all(),
            'total' => $total,
            'next_cursor' => $next < $total ? $next : null,
        ];
    }
}
