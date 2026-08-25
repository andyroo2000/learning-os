<?php

namespace App\Http\Resources\Study;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
 *     stages: list<array{number: ?int, status: ?string, cardCount: int, representativeCard: Card}>
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
            'representativeCard' => StudyCardSummaryResource::make(
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
                    'representativeCard' => StudyCardSummaryResource::make(
                        $stage['representativeCard'],
                    )->resolve($request),
                ])
                ->all(),
        ];
    }
}
