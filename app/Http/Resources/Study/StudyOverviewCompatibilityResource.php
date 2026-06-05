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
            'latestImport' => $this->latestImport($request),
            'nextDueAt' => $this->resource['next_due_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestImport(Request $request): ?array
    {
        if (($this->resource['latest_import'] ?? null) === null) {
            return null;
        }

        $latestImport = StudyImportJobResource::make(
            $this->resource['latest_import'],
        )->resolve($request);

        return [
            'id' => $latestImport['id'],
            'status' => $latestImport['status'],
            'sourceType' => $latestImport['source_type'],
            'sourceFilename' => $latestImport['source_filename'],
            'sourceContentType' => $latestImport['source_content_type'],
            'sourceSizeBytes' => $latestImport['source_size_bytes'],
            'deckName' => $latestImport['deck_name'],
            'preview' => $latestImport['preview'],
            'summary' => $latestImport['summary'],
            'errorMessage' => $latestImport['error_message'],
            'startedAt' => $latestImport['started_at'],
            'uploadedAt' => $latestImport['uploaded_at'],
            'uploadExpiresAt' => $latestImport['upload_expires_at'],
            'completedAt' => $latestImport['completed_at'],
            'createdAt' => $latestImport['created_at'],
            'updatedAt' => $latestImport['updated_at'],
        ];
    }
}
