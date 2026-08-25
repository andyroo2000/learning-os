<?php

namespace App\Http\Resources\Study;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @property-read array{
 *     id: string,
 *     groupId: ?string,
 *     representativeCard: Card,
 *     currentStageNumber: ?int,
 *     stageCount: int,
 *     cardCount: int,
 *     retiredStageCount: int,
 *     transferDemonstrated: bool,
 *     stages: list<array{
 *         number: ?int,
 *         status: ?string,
 *         cardCount: int,
 *         representativeCard: Card,
 *         cards: Collection<int, Card>
 *     }>
 * } $resource
 */
class StudyLearningItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'groupId' => $this->resource['groupId'],
            'representativeCard' => StudyLearningItemCardResource::make(
                $this->resource['representativeCard'],
            )->resolve($request),
            'currentStageNumber' => $this->resource['currentStageNumber'],
            'stageCount' => $this->resource['stageCount'],
            'cardCount' => $this->resource['cardCount'],
            'retiredStageCount' => $this->resource['retiredStageCount'],
            'transferDemonstrated' => $this->resource['transferDemonstrated'],
            'stages' => collect($this->resource['stages'])
                ->map(fn (array $stage): array => [
                    'number' => $stage['number'],
                    'status' => $stage['status'],
                    'cardCount' => $stage['cardCount'],
                    'representativeCard' => StudyLearningItemCardResource::make(
                        $stage['representativeCard'],
                    )->resolve($request),
                    'cards' => StudyLearningItemCardResource::collection($stage['cards'])
                        ->resolve($request),
                ])
                ->all(),
        ];
    }
}
