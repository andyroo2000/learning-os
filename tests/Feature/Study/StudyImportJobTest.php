<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class StudyImportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_study_import_jobs_table_has_expected_columns(): void
    {
        $this->assertDatabaseMissing('study_import_jobs', [
            'id' => 'missing',
        ]);

        $importJob = StudyImportJob::factory()->create([
            'status' => StudyImportStatus::Processing,
            'source_filename' => 'core-2k.colpkg',
            'deck_name' => 'Core 2k',
            'preview_json' => [
                'deck_name' => 'Core 2k',
                'card_count' => 10,
            ],
        ]);

        $this->assertDatabaseHas('study_import_jobs', [
            'id' => $importJob->id,
            'user_id' => $importJob->user_id,
            'status' => StudyImportStatus::Processing->value,
            'source_type' => StudyImportJob::SOURCE_TYPE_ANKI_COLPKG,
            'source_filename' => 'core-2k.colpkg',
            'deck_name' => 'Core 2k',
        ]);
    }

    public function test_it_casts_import_job_fields(): void
    {
        $importJob = StudyImportJob::factory()->create([
            'status' => StudyImportStatus::Completed,
            'source_size_bytes' => 123,
            'summary_json' => ['imported_cards' => 10],
            'completed_at' => now(),
        ]);

        $importJob->refresh();

        $this->assertSame(StudyImportStatus::Completed, $importJob->status);
        $this->assertSame(123, $importJob->source_size_bytes);
        $this->assertSame(['imported_cards' => 10], $importJob->summary_json);
        $this->assertNotNull($importJob->completed_at);
    }

    public function test_it_prevents_owner_mutation(): void
    {
        $importJob = StudyImportJob::factory()->create();
        $otherUser = User::factory()->create();

        $importJob->user_id = $otherUser->id;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study import job owner cannot be changed.');

        $importJob->save();
    }
}
