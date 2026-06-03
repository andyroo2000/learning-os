<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // No active sync clients have consumed this corrupt state; prune it without tombstones.
        // Select then delete by composite keys so batching stays portable across MySQL, PostgreSQL, and SQLite.
        // Raw query builder joins intentionally include soft-deleted cards/decks; media assets are hard-deleted by FK cascade.
        // If this aborts at the cap, remaining cross-owner pivots stay API-inaccessible until migration/admin cleanup.
        // Partial runs are safe to rerun because each batch re-selects the remaining corrupt pairs.
        // Throwing at the cap is intentional so unusually large cleanups get inspected before migration proceeds.
        $batches = 0;

        do {
            if ($batches >= 1000) {
                throw new RuntimeException('Cross-owner card media cleanup reached the maximum batch limit; inspect remaining rows, then rerun or raise the limit if it repeatedly reaches the cap.');
            }

            $stalePairs = DB::table('card_media')
                ->select('card_media.card_id', 'card_media.media_asset_id')
                ->join('cards', 'cards.id', '=', 'card_media.card_id')
                ->join('decks', 'decks.id', '=', 'cards.deck_id')
                ->join('media_assets', 'media_assets.id', '=', 'card_media.media_asset_id')
                ->whereColumn('decks.user_id', '<>', 'media_assets.user_id')
                ->orderBy('card_media.card_id')
                ->orderBy('card_media.media_asset_id')
                ->limit(500)
                ->get();

            if ($stalePairs->isEmpty()) {
                break;
            }

            // A concurrent hard delete can cascade away a selected pair; the next SELECT is the source of truth.
            // Use OR-paired predicates rather than tuple IN so the cleanup stays portable to SQLite.
            $this->constrainDeleteToPairs(DB::table('card_media'), $stalePairs)->delete();

            $batches++;
        } while (true);
    }

    /**
     * Public only to unit-test SQL portability without running the full migration; not intended for reuse.
     *
     * @param  array<int, object{card_id: int|string, media_asset_id: int|string}>|Collection<int, object{card_id: int|string, media_asset_id: int|string}>  $pairs
     */
    public function constrainDeleteToPairs(Builder $query, array|Collection $pairs): Builder
    {
        $pairs = $pairs instanceof Collection ? $pairs : collect($pairs);

        if ($pairs->isEmpty()) {
            // The migration loop breaks before delete, but keep this predicate safe for direct test/future callers.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($pairs): void {
            foreach ($pairs as $pair) {
                $query->orWhere(function (Builder $query) use ($pair): void {
                    $query->where('card_id', $pair->card_id)
                        ->where('media_asset_id', $pair->media_asset_id);
                });
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally irreversible: pruned cross-owner pivots cannot be recovered.
    }
};
