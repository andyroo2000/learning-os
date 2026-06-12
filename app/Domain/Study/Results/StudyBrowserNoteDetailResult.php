<?php

namespace App\Domain\Study\Results;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final readonly class StudyBrowserNoteDetailResult
{
    /**
     * @param  list<array{name: string, value: string|null, textValue: string|null, audio: array<string, mixed>|null, image: array<string, mixed>|null}>  $rawFields
     * @param  list<array{name: string, value: string|null, textValue: string|null, audio: array<string, mixed>|null, image: array<string, mixed>|null}>  $canonicalFields
     * @param  EloquentCollection<int, Card>  $cards
     * @param  list<array{cardId: string, reviewCount: int, lastReviewedAt: string|null}>  $cardStats
     */
    public function __construct(
        public string $noteId,
        public string $displayText,
        public ?string $noteTypeName,
        public string $sourceKind,
        public int $reviewCount,
        public ?string $lastReviewedAt,
        public ?string $updatedAt,
        public array $rawFields,
        public array $canonicalFields,
        public EloquentCollection $cards,
        public array $cardStats,
        public string $selectedCardId,
    ) {}
}
