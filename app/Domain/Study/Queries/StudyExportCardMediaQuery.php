<?php

namespace App\Domain\Study\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudyExportCardMediaQuery
{
    /**
     * @return Collection<int, object{card_id: string, media_asset_id: string, created_at: mixed, updated_at: mixed}>
     */
    public function get(int $userId): Collection
    {
        return $this->forUser($userId)
            ->select([
                'card_media.card_id',
                'card_media.media_asset_id',
                'card_media.created_at',
                'card_media.updated_at',
            ])
            ->orderBy('card_media.card_id')
            ->orderBy('card_media.media_asset_id')
            ->get();
    }

    public function count(int $userId): int
    {
        return (int) $this->forUser($userId)->count();
    }

    private function forUser(int $userId): Builder
    {
        return DB::table('card_media')
            ->join('cards', 'cards.id', '=', 'card_media.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->join('media_assets', 'media_assets.id', '=', 'card_media.media_asset_id')
            ->where('decks.user_id', $userId)
            // Media assets are hard-deleted today; if that changes, add a deleted_at guard here too.
            ->where('media_assets.user_id', $userId)
            ->whereNull('cards.deleted_at')
            ->whereNull('decks.deleted_at');
    }
}
