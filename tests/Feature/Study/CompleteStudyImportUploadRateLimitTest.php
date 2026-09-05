<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportRateLimiter;
use App\Jobs\ProcessStudyImportJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Study\Concerns\UsesStudyImportRateLimitOverrides;
use Tests\TestCase;

class CompleteStudyImportUploadRateLimitTest extends TestCase
{
    use RefreshDatabase;
    use UsesStudyImportRateLimitOverrides;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_complete_is_rate_limited_by_user(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Queue::fake();
        Storage::fake('study-imports');
        [$user, $importJobs, $otherUser, $otherImportJob] = $this->completionRateLimitFixtures();

        $this->withStudyImportRateLimitOverride(
            StudyImportRateLimiter::COMPLETE_NAME,
            [$user->id, $otherUser->id],
            function () use ($importJobs, $otherImportJob, $otherUser, $user): void {
                $this->completeFirstTwoImports($importJobs);
                $this->completeOtherUsersImport($otherUser, $otherImportJob);
                $this->assertThirdImportIsRateLimited($user, $importJobs->last());
            },
        );
    }

    /**
     * @return array{User, Collection<int, StudyImportJob>, User, StudyImportJob}
     */
    private function completionRateLimitFixtures(): array
    {
        $user = $this->signIn();
        $importJobs = StudyImportJob::factory()->count(3)->for($user)->create([
            'status' => StudyImportStatus::Failed,
            'source_size_bytes' => null,
            'uploaded_at' => null,
            'completed_at' => now(),
            'upload_expires_at' => now()->addHour(),
        ]);
        foreach ($importJobs as $index => $importJob) {
            $importJob->source_object_path = "study/imports/{$user->id}/rate-complete-{$index}/core.colpkg";
            $importJob->save();
            // Complete validates the uploaded archive from storage, then records size/upload timestamps.
            Storage::disk('study-imports')->put($importJob->source_object_path, 'PK zipped bytes');
        }

        $otherUser = User::factory()->create();
        $otherImportJob = StudyImportJob::factory()->for($otherUser)->create([
            'source_object_path' => "study/imports/{$otherUser->id}/rate-complete/core.colpkg",
            'upload_expires_at' => now()->addHour(),
        ]);
        Storage::disk('study-imports')->put($otherImportJob->source_object_path, 'PK zipped bytes');

        return [$user, $importJobs, $otherUser, $otherImportJob];
    }

    /**
     * @param  Collection<int, StudyImportJob>  $importJobs
     */
    private function completeFirstTwoImports(Collection $importJobs): void
    {
        foreach ($importJobs->take(2) as $importJob) {
            $importJob->forceFill([
                'status' => StudyImportStatus::Pending,
                'completed_at' => null,
            ])->save();

            $this
                ->postJson("/api/study/imports/{$importJob->id}/complete")
                ->assertStatus(202)
                ->assertJsonPath('data.source_size_bytes', 15)
                ->assertJsonPath('data.uploaded_at', now()->toJSON());

            // This test is about the completion throttle, not concurrent active imports.
            $importJob->forceFill([
                'status' => StudyImportStatus::Failed,
                'completed_at' => now(),
            ])->save();
        }
    }

    private function completeOtherUsersImport(User $otherUser, StudyImportJob $otherImportJob): void
    {
        $this->signIn($otherUser);

        $this
            ->postJson("/api/study/imports/{$otherImportJob->id}/complete")
            ->assertStatus(202);
    }

    private function assertThirdImportIsRateLimited(User $user, StudyImportJob $blockedImportJob): void
    {
        $this->signIn($user);
        $blockedImportJob->forceFill([
            'status' => StudyImportStatus::Pending,
            'completed_at' => null,
        ])->save();

        $this
            ->postJson("/api/study/imports/{$blockedImportJob->id}/complete")
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
        Queue::assertNotPushed(
            ProcessStudyImportJob::class,
            fn (ProcessStudyImportJob $job): bool => $job->importJobId === $blockedImportJob->id,
        );
    }
}
