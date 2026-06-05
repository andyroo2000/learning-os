<?php

namespace App\Domain\Study\Actions;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Support\Carbon;

class GetStudyExportManifestAction
{
    /**
     * @return array{
     *     exported_at: string,
     *     sections: array{
     *         courses: array{total: int, path: string},
     *         decks: array{total: int, path: string},
     *         cards: array{total: int, path: string},
     *         review_events: array{total: int, path: string},
     *         media_assets: array{total: int, path: string}
     *     }
     * }
     */
    public function handle(int $userId, ?Carbon $now = null): array
    {
        $now ??= now();

        return [
            'exported_at' => $now->toJSON(),
            'sections' => [
                'courses' => [
                    'total' => Course::query()->where('user_id', $userId)->count('id'),
                    'path' => route('api.study.export.courses', absolute: false),
                ],
                'decks' => [
                    'total' => Deck::query()->where('user_id', $userId)->count('id'),
                    'path' => route('api.study.export.decks', absolute: false),
                ],
                'cards' => [
                    'total' => $this->activeCardCount($userId),
                    'path' => route('api.study.export.cards', absolute: false),
                ],
                'review_events' => [
                    'total' => $this->activeReviewEventCount($userId),
                    'path' => route('api.study.export.review-events', absolute: false),
                ],
                'media_assets' => [
                    'total' => MediaAsset::query()->where('user_id', $userId)->count('id'),
                    'path' => route('api.study.export.media-assets', absolute: false),
                ],
            ],
        ];
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
