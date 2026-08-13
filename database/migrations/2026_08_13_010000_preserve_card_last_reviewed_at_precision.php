<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CLIENT_TIMESTAMP_PRECISION = 3;

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            $this->changePrecision(self::CLIENT_TIMESTAMP_PRECISION);
        }

        // Restore only precision that the old timestamp(0) column demonstrably lost.
        // Null/no-event cards and deliberately divergent imported state stay untouched.
        DB::affectingStatement($this->backfillSql($driver));
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        // The schema rollback truncates future storage to seconds. The data backfill is
        // intentionally irreversible because the original milliseconds cannot be inferred.
        $this->changePrecision(0);
    }

    private function changePrecision(int $precision): void
    {
        Schema::table('cards', function (Blueprint $table) use ($precision): void {
            $table->timestamp('last_reviewed_at', $precision)->nullable()->change();
        });
    }

    private function backfillSql(string $driver): string
    {
        return match ($driver) {
            'pgsql' => <<<'SQL'
                UPDATE cards
                SET last_reviewed_at = latest_review_events.reviewed_at
                FROM (
                    SELECT card_id, MAX(reviewed_at) AS reviewed_at
                    FROM card_review_events
                    GROUP BY card_id
                ) AS latest_review_events
                WHERE cards.id = latest_review_events.card_id
                  AND cards.last_reviewed_at IS NOT NULL
                  AND DATE_TRUNC('second', cards.last_reviewed_at) = DATE_TRUNC('second', latest_review_events.reviewed_at)
                  AND cards.last_reviewed_at <> latest_review_events.reviewed_at
                SQL,
            'mysql' => <<<'SQL'
                UPDATE cards
                INNER JOIN (
                    SELECT card_id, MAX(reviewed_at) AS reviewed_at
                    FROM card_review_events
                    GROUP BY card_id
                ) AS latest_review_events ON latest_review_events.card_id = cards.id
                SET cards.last_reviewed_at = latest_review_events.reviewed_at
                WHERE cards.last_reviewed_at IS NOT NULL
                  AND DATE_FORMAT(cards.last_reviewed_at, '%Y-%m-%d %H:%i:%s') = DATE_FORMAT(latest_review_events.reviewed_at, '%Y-%m-%d %H:%i:%s')
                  AND cards.last_reviewed_at <> latest_review_events.reviewed_at
                SQL,
            'sqlite' => <<<'SQL'
                UPDATE cards
                SET last_reviewed_at = (
                    SELECT MAX(card_review_events.reviewed_at)
                    FROM card_review_events
                    WHERE card_review_events.card_id = cards.id
                )
                WHERE cards.last_reviewed_at IS NOT NULL
                  AND EXISTS (
                    SELECT 1
                    FROM card_review_events
                    WHERE card_review_events.card_id = cards.id
                    GROUP BY card_review_events.card_id
                    HAVING STRFTIME('%Y-%m-%d %H:%M:%S', cards.last_reviewed_at)
                        = STRFTIME('%Y-%m-%d %H:%M:%S', MAX(card_review_events.reviewed_at))
                      AND cards.last_reviewed_at <> MAX(card_review_events.reviewed_at)
                )
                SQL,
            default => throw new RuntimeException("Unsupported database driver [{$driver}] for card review timestamp backfill."),
        };
    }
};
