<?php

namespace App\Http\Resources\Study;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyOverviewCompatibilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'dueCount' => $this->resource['due_count'],
            'failedCount' => $this->resource['failed_count'],
            'newCount' => $this->resource['new_count'],
            'newCardsPerDay' => $this->resource['new_cards_per_day'],
            'newCardsIntroducedToday' => $this->resource['new_cards_introduced_today'],
            'newCardsAvailableToday' => $this->resource['new_cards_available_today'],
            'learningCount' => $this->resource['learning_count'],
            'reviewCount' => $this->resource['review_count'],
            'suspendedCount' => $this->resource['suspended_count'],
            'totalCards' => $this->resource['total_cards'],
            'latestImport' => ($this->resource['latest_import'] ?? null) === null
                ? null
                : StudyImportJobResource::make($this->resource['latest_import'])->resolve($request),
            'nextDueAt' => $this->resource['next_due_at'] ?? null,
        ];
    }
}
