<?php

namespace App\Domain\Study\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

class GetStudyExportManifestAction
{
    /**
     * @return array{
     *     exported_at: string,
     *     current_checkpoint: int,
     *     sections: array{
     *         settings: array{total: int},
     *         courses: array{total: int},
     *         decks: array{total: int},
     *         cards: array{total: int},
     *         card_drafts: array{total: int},
     *         card_media: array{total: int},
     *         review_events: array{total: int},
     *         imports: array{total: int},
     *         media_assets: array{total: int}
     *     }
     * }
     */
    public function handle(int $userId, ?Carbon $now = null): array
    {
        $now ??= now();
        $metrics = $this->exportMetrics($userId);

        return [
            'exported_at' => $now->toJSON(),
            'current_checkpoint' => $metrics['current_checkpoint'],
            'sections' => [
                'settings' => ['total' => 1],
                'courses' => ['total' => $metrics['courses_total']],
                'decks' => ['total' => $metrics['decks_total']],
                'cards' => ['total' => $metrics['cards_total']],
                'card_drafts' => ['total' => $metrics['card_drafts_total']],
                'card_media' => ['total' => $metrics['card_media_total']],
                'review_events' => ['total' => $metrics['review_events_total']],
                'imports' => ['total' => $metrics['imports_total']],
                'media_assets' => ['total' => $metrics['media_assets_total']],
            ],
        ];
    }

    /**
     * @return array{
     *     current_checkpoint: int,
     *     courses_total: int,
     *     decks_total: int,
     *     cards_total: int,
     *     card_drafts_total: int,
     *     card_media_total: int,
     *     review_events_total: int,
     *     imports_total: int,
     *     media_assets_total: int,
     * }
     */
    private function exportMetrics(int $userId): array
    {
        // Scalar subqueries keep the manifest as one portable snapshot read across SQLite, MySQL, and Postgres.
        // The outer no-FROM SELECT returns one row in all three supported engines.
        // Only courses, decks, and cards have soft-delete filters; the other exported tables are hard-deleted today.
        $sql = <<<'SQL'
                COALESCE((SELECT MAX(sync_feed_entries.checkpoint) FROM sync_feed_entries WHERE sync_feed_entries.user_id = ?), 0) AS current_checkpoint,
                (SELECT COUNT(courses.id) FROM courses WHERE courses.user_id = ? AND courses.deleted_at IS NULL) AS courses_total,
                (SELECT COUNT(decks.id) FROM decks WHERE decks.user_id = ? AND decks.deleted_at IS NULL) AS decks_total,
                (
                    SELECT COUNT(cards.id)
                    FROM cards
                    INNER JOIN decks ON decks.id = cards.deck_id
                    WHERE decks.user_id = ?
                        AND cards.deleted_at IS NULL
                        AND decks.deleted_at IS NULL
                ) AS cards_total,
                (SELECT COUNT(study_card_drafts.id) FROM study_card_drafts WHERE study_card_drafts.user_id = ?) AS card_drafts_total,
                (
                    SELECT COUNT(*)
                    FROM card_media
                    INNER JOIN cards ON cards.id = card_media.card_id
                    INNER JOIN decks ON decks.id = cards.deck_id
                    INNER JOIN media_assets ON media_assets.id = card_media.media_asset_id
                    WHERE decks.user_id = ?
                        AND media_assets.user_id = ?
                        AND cards.deleted_at IS NULL
                        AND decks.deleted_at IS NULL
                ) AS card_media_total,
                (
                    SELECT COUNT(card_review_events.id)
                    FROM card_review_events
                    INNER JOIN cards ON cards.id = card_review_events.card_id
                    INNER JOIN decks ON decks.id = cards.deck_id
                    WHERE decks.user_id = ?
                        AND cards.deleted_at IS NULL
                        AND decks.deleted_at IS NULL
                ) AS review_events_total,
                (SELECT COUNT(study_import_jobs.id) FROM study_import_jobs WHERE study_import_jobs.user_id = ?) AS imports_total,
                (SELECT COUNT(media_assets.id) FROM media_assets WHERE media_assets.user_id = ?) AS media_assets_total
                SQL;

        // One user binding per ? placeholder above.
        $row = DB::query()
            ->selectRaw($sql, array_fill(0, 10, $userId))
            ->first() ?? throw new LogicException('Study export metrics query returned no row.');

        return [
            'current_checkpoint' => (int) $row->current_checkpoint,
            'courses_total' => (int) $row->courses_total,
            'decks_total' => (int) $row->decks_total,
            'cards_total' => (int) $row->cards_total,
            'card_drafts_total' => (int) $row->card_drafts_total,
            'card_media_total' => (int) $row->card_media_total,
            'review_events_total' => (int) $row->review_events_total,
            'imports_total' => (int) $row->imports_total,
            'media_assets_total' => (int) $row->media_assets_total,
        ];
    }
}
