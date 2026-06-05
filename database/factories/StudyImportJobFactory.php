<?php

namespace Database\Factories;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudyImportJob>
 */
class StudyImportJobFactory extends Factory
{
    protected $model = StudyImportJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => StudyImportStatus::Pending,
            'source_type' => StudyImportJob::SOURCE_TYPE_ANKI_COLPKG,
            'source_filename' => 'japanese.colpkg',
            'source_object_path' => null,
            'source_content_type' => 'application/zip',
            'source_size_bytes' => 123456,
            'deck_name' => StudyImportJob::DEFAULT_DECK_NAME,
            'preview_json' => $this->emptyPreview(),
            'summary_json' => null,
            'error_message' => null,
            'started_at' => null,
            'uploaded_at' => null,
            'upload_expires_at' => null,
            'completed_at' => null,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => StudyImportStatus::Processing,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => StudyImportStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => StudyImportStatus::Failed,
            'error_message' => 'Import failed.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPreview(): array
    {
        return [
            'deck_name' => StudyImportJob::DEFAULT_DECK_NAME,
            'card_count' => 0,
            'note_count' => 0,
            'review_log_count' => 0,
            'media_reference_count' => 0,
            'skipped_media_count' => 0,
            'warnings' => [],
            'note_type_breakdown' => [],
        ];
    }
}
