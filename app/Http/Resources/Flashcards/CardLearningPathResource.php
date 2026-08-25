<?php

namespace App\Http\Resources\Flashcards;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @property-read array{anchor: Card, cards: Collection<int, Card>} $resource */
class CardLearningPathResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Card $anchor */
        $anchor = $this->resource['anchor'];
        /** @var Collection<int, Card> $cards */
        $cards = $this->resource['cards'];

        return [
            'group_id' => is_string($anchor->variant_group_id) && trim($anchor->variant_group_id) !== ''
                ? $anchor->variant_group_id
                : null,
            'anchor_card_id' => $anchor->id,
            'stages' => $cards
                ->groupBy('variant_stage')
                ->map(fn (Collection $stageCards, int|string $stage): array => [
                    // Partial legacy metadata remains visible for repair without
                    // pretending that a missing stage is a valid stage zero.
                    'number' => is_numeric($stage) && (int) $stage > 0 ? (int) $stage : null,
                    'cards' => CardResource::collection($stageCards->values()),
                ])
                ->values(),
        ];
    }
}
