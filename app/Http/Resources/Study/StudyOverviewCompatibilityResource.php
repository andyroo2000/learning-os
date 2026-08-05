<?php

namespace App\Http\Resources\Study;

use App\Domain\Study\Models\StudyImportJob;
use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyOverviewCompatibilityResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'dueCount' => $this->overviewValue('due_count'),
            'failedCount' => $this->overviewValue('failed_count'),
            'failedDueCount' => $this->overviewValue('failed_due_count'),
            'newCount' => $this->overviewValue('new_count'),
            'newCardsPerDay' => $this->overviewValue('new_cards_per_day'),
            'lessonBatchSize' => $this->overviewValue('lesson_batch_size'),
            'newCardsIntroducedToday' => $this->overviewValue('new_cards_introduced_today'),
            'newCardsAvailableToday' => $this->overviewValue('new_cards_available_today'),
            'learningCount' => $this->overviewValue('learning_count'),
            'reviewCount' => $this->overviewValue('review_count'),
            'suspendedCount' => $this->overviewValue('suspended_count'),
            'totalCards' => $this->overviewValue('total_cards'),
            'latestImport' => $this->latestImport(),
            'nextDueAt' => $this->overviewValue('next_due_at'),
            'masterySpread' => $this->overviewValue('mastery_spread'),
            'learningReadiness' => $this->learningReadiness(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function learningReadiness(): ?array
    {
        $readiness = $this->resource['learning_readiness'] ?? null;

        if (! is_array($readiness)) {
            return null;
        }

        return [
            'recommendation' => $readiness['recommendation'],
            'readinessLevel' => $readiness['readiness_level'],
            'sampleSize' => $readiness['sample_size'],
            'sufficientData' => $readiness['sufficient_data'],
            'recentRecall' => $readiness['recent_recall'],
            'targetRecall' => $readiness['target_recall'],
            'dueBacklog' => $readiness['due_backlog'],
            'apprenticeCount' => $readiness['apprentice_count'],
            'projectedSevenDayReviews' => $readiness['projected_seven_day_reviews'],
            'timedReviewSampleSize' => $readiness['timed_review_sample_size'],
            'medianReviewDurationSeconds' => $readiness['median_review_duration_seconds'],
            'projectedDailyReviewMinutes' => $readiness['projected_daily_review_minutes'],
            'reviewTimeBudgetMinutes' => $readiness['review_time_budget_minutes'],
            'reviewTimeHeadroomMinutes' => $readiness['review_time_headroom_minutes'],
            'suggestedBatchSize' => $readiness['suggested_batch_size'],
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
            'id' => $latestImport->clientId(),
            'status' => $this->importStatusValue($latestImport),
            'sourceType' => $latestImport->source_type,
            'sourceFilename' => $latestImport->source_filename,
            'sourceContentType' => $latestImport->source_content_type,
            'sourceSizeBytes' => $latestImport->source_size_bytes,
            'deckName' => $latestImport->deck_name,
            // ConvoLab import views consume the full preview/summary structures from the import job.
            'preview' => $latestImport->preview_json,
            'summary' => $latestImport->summary_json,
            'errorMessage' => $latestImport->error_message,
            'startedAt' => ConvoLabTimestamp::serialize($latestImport->started_at),
            'uploadedAt' => ConvoLabTimestamp::serialize($latestImport->uploaded_at),
            'uploadExpiresAt' => ConvoLabTimestamp::serialize($latestImport->upload_expires_at),
            'completedAt' => ConvoLabTimestamp::serialize($latestImport->completed_at),
            'createdAt' => ConvoLabTimestamp::serialize($latestImport->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($latestImport->updated_at),
        ];
    }

    private function importStatusValue(StudyImportJob $latestImport): ?string
    {
        // Use the raw value so legacy/out-of-range rows cannot trigger enum cast failures while serializing.
        $status = $latestImport->getAttributes()['status'] ?? null;

        return $status === null ? null : (string) $status;
    }
}
