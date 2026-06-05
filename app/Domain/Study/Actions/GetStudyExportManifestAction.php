<?php

namespace App\Domain\Study\Actions;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Support\Carbon;

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
     *         review_events: array{total: int},
     *         imports: array{total: int},
     *         media_assets: array{total: int}
     *     }
     * }
     */
    public function handle(int $userId, ?Carbon $now = null): array
    {
        $now ??= now();

        return [
            'exported_at' => $now->toJSON(),
            'current_checkpoint' => $this->currentCheckpoint($userId),
            'sections' => [
                'settings' => ['total' => 1],
                'courses' => ['total' => Course::query()->where('user_id', $userId)->count('id')],
                'decks' => ['total' => Deck::query()->where('user_id', $userId)->count('id')],
                'cards' => ['total' => $this->activeCardCount($userId)],
                'review_events' => ['total' => $this->activeReviewEventCount($userId)],
                'imports' => ['total' => StudyImportJob::query()->where('user_id', $userId)->count('id')],
                'media_assets' => ['total' => MediaAsset::query()->where('user_id', $userId)->count('id')],
            ],
        ];
    }

    private function currentCheckpoint(int $userId): int
    {
        return (int) SyncFeedEntry::query()
            ->where('user_id', $userId)
            ->max('checkpoint');
    }

    private function activeCardCount(int $userId): int
    {
        return Card::query()
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('cards.deleted_at')
            ->whereNull('decks.deleted_at')
            ->count('cards.id');
    }

    private function activeReviewEventCount(int $userId): int
    {
        return CardReviewEvent::query()
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->whereNull('cards.deleted_at')
            ->whereNull('decks.deleted_at')
            ->count('card_review_events.id');
    }
}
