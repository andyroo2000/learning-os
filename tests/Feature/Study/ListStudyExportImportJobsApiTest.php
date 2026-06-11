<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListStudyExportImportJobsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/study/export/imports')->assertUnauthorized();
    }

    public function test_index_returns_import_jobs_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        $firstImport = StudyImportJob::factory()->for($user)->create([
            'source_filename' => 'first.colpkg',
            'source_object_path' => 'study/imports/internal/first.colpkg',
            'deck_name' => 'First Deck',
            'preview_json' => [
                'deck_name' => 'First Deck',
                'card_count' => 10,
            ],
        ]);
        $secondImport = StudyImportJob::factory()->completed()->for($user)->create([
            'source_filename' => 'second.colpkg',
            'deck_name' => 'Second Deck',
            'summary_json' => [
                'imported_cards' => 8,
            ],
        ]);
        $otherImport = StudyImportJob::factory()->for($otherUser)->create();

        $this->getJson('/api/study/export/imports')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $firstImport->id)
            ->assertJsonPath('data.0.status', StudyImportStatus::Pending->value)
            ->assertJsonPath('data.0.source_filename', 'first.colpkg')
            ->assertJsonPath('data.0.deck_name', 'First Deck')
            ->assertJsonPath('data.0.preview.card_count', 10)
            ->assertJsonPath('data.1.id', $secondImport->id)
            ->assertJsonPath('data.1.status', StudyImportStatus::Completed->value)
            ->assertJsonPath('data.1.summary.imported_cards', 8)
            ->assertJsonMissingPath('data.0.source_object_path')
            ->assertJsonMissing([
                'id' => $otherImport->id,
            ])
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'status',
                        'source_type',
                        'source_filename',
                        'source_content_type',
                        'source_size_bytes',
                        'deck_name',
                        'preview',
                        'summary',
                        'error_message',
                        'started_at',
                        'uploaded_at',
                        'upload_completed_at',
                        'upload_expires_at',
                        'completed_at',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }
}
