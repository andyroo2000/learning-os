<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportRateLimiter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Study\Concerns\MakesStudyImportUploadRequests;
use Tests\Feature\Study\Concerns\UsesStudyImportRateLimitOverrides;
use Tests\TestCase;

class StudyImportFileUploadApiTest extends TestCase
{
    use MakesStudyImportUploadRequests;
    use RefreshDatabase;
    use UsesStudyImportRateLimitOverrides;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_upload_stores_the_import_file(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = $this->signIn();
        $createResponse = $this->postJson('/api/study/imports', [
            'filename' => 'core.colpkg',
            'content_type' => 'application/zip',
        ])->assertCreated();
        $importJobId = $createResponse->json('data.import_job.id');
        $uploadUrl = '/api/study/imports/'.strtoupper($importJobId).'/upload';
        $contents = 'anki bytes';

        $response = $this->putImportUpload($uploadUrl, $contents, 'application/zip', strlen($contents));

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $importJobId)
            ->assertJsonPath('data.source_size_bytes', strlen($contents))
            ->assertJsonPath('data.uploaded_at', now()->toJSON())
            ->assertJsonPath('data.upload_completed_at', null)
            ->assertJsonMissingPath('data.source_object_path');

        $importJob = StudyImportJob::query()->findOrFail($importJobId);
        $this->assertSame($user->id, $importJob->user_id);
        Storage::disk('study-imports')->assertExists($importJob->source_object_path);
        $this->assertSame($contents, Storage::disk('study-imports')->get($importJob->source_object_path));
    }

    public function test_upload_is_rate_limited_by_user(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = $this->signIn();
        $importJobs = StudyImportJob::factory()->count(3)->for($user)->create([
            'source_content_type' => 'application/zip',
            'source_size_bytes' => null,
            'uploaded_at' => null,
            'upload_expires_at' => now()->addHour(),
        ]);
        foreach ($importJobs as $index => $importJob) {
            $importJob->source_object_path = "study/imports/{$user->id}/rate-upload-{$index}/core.colpkg";
            $importJob->save();
        }
        $otherUser = User::factory()->create();
        $otherImportJob = StudyImportJob::factory()->for($otherUser)->create([
            'source_content_type' => 'application/zip',
            'source_object_path' => "study/imports/{$otherUser->id}/rate-upload/core.colpkg",
            'upload_expires_at' => now()->addHour(),
        ]);

        $this->withStudyImportRateLimitOverride(
            StudyImportRateLimiter::UPLOAD_NAME,
            [$user->id, $otherUser->id],
            function () use ($importJobs, $otherImportJob, $otherUser, $user): void {
                foreach ($importJobs->take(2) as $index => $importJob) {
                    $contents = "PK upload {$index}";

                    $this
                        ->putImportUpload("/api/study/imports/{$importJob->id}/upload", $contents, 'application/zip', strlen($contents))
                        ->assertOk();
                }

                $this->signIn($otherUser);

                $otherContents = 'PK other upload';
                $this
                    ->putImportUpload("/api/study/imports/{$otherImportJob->id}/upload", $otherContents, 'application/zip', strlen($otherContents))
                    ->assertOk();

                $this->signIn($user);

                $blockedImportJob = $importJobs->last();
                $blockedContents = 'PK blocked upload';

                $this
                    ->putImportUpload("/api/study/imports/{$blockedImportJob->id}/upload", $blockedContents, 'application/zip', strlen($blockedContents))
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->getJson("/api/study/imports/{$blockedImportJob->id}")
                    ->assertOk()
                    ->assertJsonPath('data.source_size_bytes', null)
                    ->assertJsonPath('data.uploaded_at', null);

                $this->assertNull($blockedImportJob->refresh()->source_size_bytes);
                $this->assertNull($blockedImportJob->uploaded_at);
                Storage::disk('study-imports')->assertMissing($blockedImportJob->source_object_path);
            },
        );
    }

    public function test_upload_hides_cross_user_import_jobs(): void
    {
        $this->signIn();
        $importJob = StudyImportJob::factory()->create([
            'source_object_path' => 'study/imports/1/cross-user/core.colpkg',
        ]);

        $this->putImportUpload('/api/study/imports/'.$importJob->id.'/upload', 'anki bytes', $importJob->source_content_type)
            ->assertNotFound();
    }

    public function test_upload_rejects_invalid_state_content_type_and_expired_sessions(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = $this->signIn();

        $completed = StudyImportJob::factory()->completed()->for($user)->create();
        $this->putImportUpload('/api/study/imports/'.$completed->id.'/upload', 'anki bytes', $completed->source_content_type)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'study_import_not_pending');

        $pending = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => 'study/imports/'.$user->id.'/pending/core.colpkg',
            'source_content_type' => 'application/zip',
        ]);
        $this->putImportUpload('/api/study/imports/'.$pending->id.'/upload', 'anki bytes', 'application/octet-stream')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content_type']);

        $expired = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => 'study/imports/'.$user->id.'/expired/core.colpkg',
            'upload_expires_at' => now()->subSecond(),
        ]);
        $this->putImportUpload('/api/study/imports/'.$expired->id.'/upload', 'anki bytes', $expired->source_content_type)
            ->assertStatus(410)
            ->assertJsonPath('reason', 'study_import_upload_expired');

        $this->assertSame(StudyImportStatus::Failed, $expired->refresh()->status);
    }

    public function test_upload_rejects_already_completed_uploads_without_overwriting_the_archive(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = $this->signIn();
        $sourceObjectPath = 'study/imports/'.$user->id.'/completed-upload/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK original bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
            'source_content_type' => 'application/zip',
            'uploaded_at' => now()->subMinute(),
            'upload_completed_at' => now()->subMinute(),
            'upload_expires_at' => now()->addHour(),
        ]);

        $this->putImportUpload('/api/study/imports/'.$importJob->id.'/upload', 'PK replacement bytes', 'application/zip')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'study_import_upload_completed');

        $this->assertSame('PK original bytes', Storage::disk('study-imports')->get($sourceObjectPath));
        $this->assertSame(now()->subMinute()->toJSON(), $importJob->refresh()->upload_completed_at?->toJSON());
    }

    public function test_upload_rejects_empty_uploads(): void
    {
        $user = $this->signIn();
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => 'study/imports/'.$user->id.'/empty/core.colpkg',
            'source_content_type' => 'application/zip',
        ]);

        $this->putImportUpload('/api/study/imports/'.$importJob->id.'/upload', '', 'application/zip')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_upload_rejects_mismatched_content_length_headers(): void
    {
        Storage::fake('study-imports');
        $user = $this->signIn();
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => 'study/imports/'.$user->id.'/mismatched-length/core.colpkg',
            'source_content_type' => 'application/zip',
        ]);
        $originalSizeBytes = $importJob->source_size_bytes;

        $this->putImportUpload('/api/study/imports/'.$importJob->id.'/upload', 'anki bytes', 'application/zip', 11)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $importJob->refresh();

        $this->assertSame($originalSizeBytes, $importJob->source_size_bytes);
        $this->assertNull($importJob->uploaded_at);
        Storage::disk('study-imports')->assertMissing($importJob->source_object_path);
    }
}
