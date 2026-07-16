<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class StudyImportJobTest extends TestCase
{
    use RefreshDatabase;

    private const CONVOLAB_IMPORT_ID = '98f42a62-8303-410e-ad4d-5a69c55911bb';

    public function test_study_import_jobs_table_has_expected_columns(): void
    {
        $this->assertFalse(
            Schema::hasColumn('study_import_jobs', 'deleted_at'),
            'Study import jobs are hard-deleted; export manifest counts intentionally have no import deleted_at filter.',
        );
        $this->assertTrue(Schema::hasColumn('study_import_jobs', 'convolab_id'));

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

    public function test_it_prevents_convolab_identifier_mutation(): void
    {
        $importJob = StudyImportJob::factory()->create([
            'convolab_id' => self::CONVOLAB_IMPORT_ID,
        ]);

        $importJob->convolab_id = '45db472e-6f83-4aa4-845d-ddd7c894a1fd';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study import job ConvoLab identifier cannot be changed.');

        $importJob->save();
    }

    public function test_client_id_prefers_the_convolab_identifier_and_falls_back_to_the_native_ulid(): void
    {
        $copiedImport = StudyImportJob::factory()->create([
            'convolab_id' => self::CONVOLAB_IMPORT_ID,
        ]);
        $nativeImport = StudyImportJob::factory()->create();

        $this->assertSame(self::CONVOLAB_IMPORT_ID, $copiedImport->clientId());
        $this->assertSame($nativeImport->id, $nativeImport->clientId());
    }
}
