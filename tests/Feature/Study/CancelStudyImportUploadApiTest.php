<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportRateLimiter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Study\Concerns\UsesStudyImportRateLimitOverrides;
use Tests\TestCase;

class CancelStudyImportUploadApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesStudyImportRateLimitOverrides;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_cancel_marks_pending_uploads_failed(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = $this->signIn();
        $sourceObjectPath = 'study/imports/'.$user->id.'/cancel/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $this->postJson('/api/study/imports/'.strtoupper($importJob->id).'/cancel')
            ->assertOk()
            ->assertJsonPath('data.id', $importJob->id)
            ->assertJsonPath('data.status', StudyImportStatus::Failed->value)
            ->assertJsonPath('data.error_message', 'Study import upload was cancelled.')
            ->assertJsonPath('data.completed_at', now()->toJSON());

        Storage::disk('study-imports')->assertMissing($sourceObjectPath);
    }

    public function test_cancel_is_rate_limited_by_user(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = $this->signIn();
        $importJobs = StudyImportJob::factory()->count(3)->for($user)->create();
        foreach ($importJobs as $index => $importJob) {
            $importJob->source_object_path = "study/imports/{$user->id}/rate-cancel-{$index}/core.colpkg";
            $importJob->save();
            Storage::disk('study-imports')->put($importJob->source_object_path, 'PK zipped bytes');
        }
        $otherUser = User::factory()->create();
        $otherImportJob = StudyImportJob::factory()->for($otherUser)->create([
            'source_object_path' => "study/imports/{$otherUser->id}/rate-cancel/core.colpkg",
        ]);
        Storage::disk('study-imports')->put($otherImportJob->source_object_path, 'PK zipped bytes');

        $this->withStudyImportRateLimitOverride(
            StudyImportRateLimiter::CANCEL_NAME,
            [$user->id, $otherUser->id],
            function () use ($importJobs, $otherImportJob, $otherUser, $user): void {
                foreach ($importJobs->take(2) as $importJob) {
                    $this
                        ->postJson("/api/study/imports/{$importJob->id}/cancel")
                        ->assertOk();
                }

                $this->signIn($otherUser);

                $this
                    ->postJson("/api/study/imports/{$otherImportJob->id}/cancel")
                    ->assertOk();

                $this->signIn($user);

                $blockedImportJob = $importJobs->last();

                $this
                    ->postJson("/api/study/imports/{$blockedImportJob->id}/cancel")
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->getJson("/api/study/imports/{$blockedImportJob->id}")
                    ->assertOk()
                    ->assertJsonPath('data.status', StudyImportStatus::Pending->value)
                    ->assertJsonPath('data.error_message', null);

                $this->assertSame(StudyImportStatus::Pending, $blockedImportJob->refresh()->status);
                $this->assertNull($blockedImportJob->error_message);
                $this->assertNull($blockedImportJob->completed_at);
                Storage::disk('study-imports')->assertExists($blockedImportJob->source_object_path);
            },
        );
    }

    public function test_cancel_hides_cross_user_import_jobs_and_rejects_processing_imports(): void
    {
        $this->signIn();
        $crossUser = StudyImportJob::factory()->create();

        $this->postJson('/api/study/imports/'.$crossUser->id.'/cancel')
            ->assertNotFound();

        $user = $this->signIn();
        $processing = StudyImportJob::factory()->processing()->for($user)->create();

        $this->postJson('/api/study/imports/'.$processing->id.'/cancel')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'study_import_processing');
    }
}
