<?php

namespace App\Http\Resources\Study;

use App\Domain\Study\Models\StudyImportJob;
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
            'dueCount' => $this->overviewValue('due_count'),
            'failedCount' => $this->overviewValue('failed_count'),
            'newCount' => $this->overviewValue('new_count'),
            'newCardsPerDay' => $this->overviewValue('new_cards_per_day'),
            'newCardsIntroducedToday' => $this->overviewValue('new_cards_introduced_today'),
            'newCardsAvailableToday' => $this->overviewValue('new_cards_available_today'),
            'learningCount' => $this->overviewValue('learning_count'),
            'reviewCount' => $this->overviewValue('review_count'),
            'suspendedCount' => $this->overviewValue('suspended_count'),
            'totalCards' => $this->overviewValue('total_cards'),
            'latestImport' => $this->latestImport(),
            'nextDueAt' => $this->overviewValue('next_due_at'),
        ];
    }

    private function overviewValue(string $key): mixed
    {
        return $this->resource[$key] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestImport(): ?array
    {
        $latestImport = $this->resource['latest_import'] ?? null;

        if (! $latestImport instanceof StudyImportJob) {
            return null;
        }

        return [
            'id' => $latestImport->id,
            'status' => $this->importStatusValue($latestImport),
            'sourceType' => $latestImport->source_type,
            'sourceFilename' => $latestImport->source_filename,
            'sourceContentType' => $latestImport->source_content_type,
            'sourceSizeBytes' => $latestImport->source_size_bytes,
            'deckName' => $latestImport->deck_name,
            'preview' => $latestImport->preview_json,
            'summary' => $latestImport->summary_json,
            'errorMessage' => $latestImport->error_message,
            'startedAt' => $latestImport->started_at?->toJSON(),
            'uploadedAt' => $latestImport->uploaded_at?->toJSON(),
            'uploadExpiresAt' => $latestImport->upload_expires_at?->toJSON(),
            'completedAt' => $latestImport->completed_at?->toJSON(),
            'createdAt' => $latestImport->created_at?->toJSON(),
            'updatedAt' => $latestImport->updated_at?->toJSON(),
        ];
    }

    private function importStatusValue(StudyImportJob $latestImport): ?string
    {
        // Use the raw value so legacy/out-of-range rows cannot trigger enum cast failures while serializing.
        $status = $latestImport->getAttributes()['status'] ?? null;

        return $status === null ? null : (string) $status;
    }
}
