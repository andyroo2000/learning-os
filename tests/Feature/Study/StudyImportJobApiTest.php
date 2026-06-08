<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyImportJobApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_requires_authentication(): void
    {
        $this->getJson('/api/study/imports/current')->assertUnauthorized();
    }

    public function test_readiness_requires_authentication(): void
    {
        $this->getJson('/api/study/imports/readiness')->assertUnauthorized();
    }

    public function test_readiness_returns_upload_availability(): void
    {
        $this->signIn();

        $this->getJson('/api/study/imports/readiness')
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('message', null)
            ->assertJsonStructure([
                'ready',
                'message',
            ]);
    }

    public function test_readiness_reports_unavailable_when_storage_is_not_configured(): void
    {
        $this->signIn();
        config()->offsetUnset('filesystems.disks.study-imports');

        $this->getJson('/api/study/imports/readiness')
            ->assertOk()
            ->assertJsonPath('ready', false)
            ->assertJsonPath('message', 'Study import uploads are temporarily unavailable because storage is not configured.');
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/study/imports/'.strtolower((string) Str::ulid()))->assertUnauthorized();
    }

    public function test_current_returns_latest_active_import_job_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();
        $current = StudyImportJob::factory()->processing()->for($user)->create([
            'source_filename' => 'current.colpkg',
            'deck_name' => 'Current Deck',
            'preview_json' => [
                'deck_name' => 'Current Deck',
                'card_count' => 12,
            ],
        ]);
        StudyImportJob::factory()->completed()->for($user)->create([
            'updated_at' => now()->addMinute(),
        ]);
        StudyImportJob::factory()->for($otherUser)->create([
            'updated_at' => now()->addMinutes(2),
        ]);

        $this->getJson('/api/study/imports/current')
            ->assertOk()
            ->assertJsonPath('data.id', $current->id)
            ->assertJsonPath('data.status', StudyImportStatus::Processing->value)
            ->assertJsonPath('data.source_type', StudyImportJob::SOURCE_TYPE_ANKI_COLPKG)
            ->assertJsonPath('data.source_filename', 'current.colpkg')
            ->assertJsonPath('data.deck_name', 'Current Deck')
            ->assertJsonPath('data.preview.deck_name', 'Current Deck')
            ->assertJsonPath('data.preview.card_count', 12)
            ->assertJsonPath('data.summary', null)
            ->assertJsonPath('data.error_message', null)
            ->assertJsonStructure([
                'data' => [
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
                    'upload_expires_at',
                    'completed_at',
                    'created_at',
                    'updated_at',
                ],
            ]);
    }

    public function test_current_returns_null_when_no_active_import_job_exists(): void
    {
        $this->signIn();

        $this->getJson('/api/study/imports/current')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonFragment(['data' => null]);
    }

    public function test_current_expires_stale_processing_imports_before_returning_active_import(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');

        try {
            $user = $this->signIn();
            $stale = StudyImportJob::factory()->processing()->for($user)->create([
                'started_at' => now()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1),
                'updated_at' => now()->addMinute(),
            ]);
            $fresh = StudyImportJob::factory()->for($user)->create([
                'updated_at' => now(),
            ]);

            $this->getJson('/api/study/imports/current')
                ->assertOk()
                ->assertJsonPath('data.id', $fresh->id)
                ->assertJsonPath('data.status', StudyImportStatus::Pending->value);

            $this->assertSame(StudyImportStatus::Failed, $stale->refresh()->status);
            $this->assertSame('Study import timed out before completion.', $stale->error_message);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_show_returns_the_authenticated_users_import_job(): void
    {
        $user = $this->signIn();
        $importJob = StudyImportJob::factory()->completed()->for($user)->create([
            'summary_json' => ['imported_cards' => 3],
        ]);
        StudyImportJob::factory()->for(User::factory()->create())->create();

        $this->getJson('/api/study/imports/'.strtoupper($importJob->id))
            ->assertOk()
            ->assertJsonPath('data.id', $importJob->id)
            ->assertJsonPath('data.status', StudyImportStatus::Completed->value)
            ->assertJsonPath('data.summary.imported_cards', 3);
    }

    public function test_show_hides_cross_user_import_jobs(): void
    {
        $this->signIn();
        $importJob = StudyImportJob::factory()->create();

        $this->getJson('/api/study/imports/'.$importJob->id)
            ->assertNotFound();
    }
}
