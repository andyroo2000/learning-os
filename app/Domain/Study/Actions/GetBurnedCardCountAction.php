<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Enums\StudyMasteryLevel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use UnexpectedValueException;

final class GetBurnedCardCountAction
{
    public function handle(int $userId): int
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Study milestone user ID must be positive.');
        }

        $stability = $this->schedulerStabilityExpression();

        return Card::query()
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('decks.deleted_at')
            ->whereProgressionAvailable()
            ->where('cards.study_status', CardStudyStatus::Review->value)
            ->whereRaw("{$stability} >= ?", [StudyMasteryLevel::BURNED_STABILITY_DAYS])
            ->count('cards.id');
    }

    private function schedulerStabilityExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CAST(json_extract(cards.scheduler_state, '$.stability') AS REAL)",
            'pgsql' => "CAST(cards.scheduler_state->>'stability' AS DOUBLE PRECISION)",
            'mysql' => "CAST(JSON_UNQUOTE(JSON_EXTRACT(cards.scheduler_state, '$.stability')) AS DECIMAL(20, 6))",
            default => throw new UnexpectedValueException('Unsupported database driver for study milestone aggregation.'),
        };
    }
}
