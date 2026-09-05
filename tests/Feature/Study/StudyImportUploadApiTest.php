<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportRateLimiter;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Study\Concerns\UsesStudyImportRateLimitOverrides;
use Tests\TestCase;

class StudyImportUploadApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesStudyImportRateLimitOverrides;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/study/imports', [
            'filename' => 'core.colpkg',
        ])->assertUnauthorized();
    }

    public function test_store_creates_an_upload_session(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = $this->signIn();

        $response = $this->postJson('/api/study/imports', [
            'filename' => ' Core.COLPKG ',
            'content_type' => ' APPLICATION/ZIP ',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.import_job.status', StudyImportStatus::Pending->value)
            ->assertJsonPath('data.import_job.source_filename', 'Core.COLPKG')
            ->assertJsonPath('data.import_job.source_content_type', 'application/zip')
            ->assertJsonPath('data.import_job.source_size_bytes', null)
            ->assertJsonPath('data.import_job.uploaded_at', null)
            ->assertJsonPath('data.import_job.upload_completed_at', null)
            ->assertJsonPath('data.import_job.upload_expires_at', now()->addMinutes(StudyImportJob::UPLOAD_SESSION_TTL_MINUTES)->toJSON())
            ->assertJsonPath('data.upload.method', 'PUT')
            ->assertJsonPath('data.upload.headers.Content-Type', 'application/zip')
            ->assertJsonMissingPath('data.import_job.source_object_path');

        $importJobId = $response->json('data.import_job.id');

        $this->assertTrue(Str::isUlid($importJobId));
        $this->assertSame('/api/study/imports/'.$importJobId.'/upload', $response->json('data.upload.url'));

        $importJob = StudyImportJob::query()->findOrFail($importJobId);
        $this->assertSame($user->id, $importJob->user_id);
        $this->assertSame(StudyImportJob::SOURCE_UPLOAD_FOLDER.'/'.$user->id.'/'.$importJobId.'/Core.COLPKG', $importJob->source_object_path);
    }

    public function test_store_defaults_blank_content_type_without_middleware_trim(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/study/imports', [
                'filename' => '  Core.COLPKG  ',
                'content_type' => '  ',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.import_job.source_filename', 'Core.COLPKG')
            ->assertJsonPath('data.import_job.source_content_type', StudyImportJob::DEFAULT_CONTENT_TYPE)
            ->assertJsonPath('data.upload.headers.Content-Type', StudyImportJob::DEFAULT_CONTENT_TYPE);
    }

    public function test_store_rejects_malformed_inputs(): void
    {
        $this->signIn();

        $this->postJson('/api/study/imports', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filename']);

        $this->postJson('/api/study/imports', [
            'filename' => ['core.colpkg'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filename']);

        $this->postJson('/api/study/imports', [
            'filename' => '../core.colpkg',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filename']);

        $this->postJson('/api/study/imports', [
            'filename' => 'core.zip',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filename']);

        $this->postJson('/api/study/imports', [
            'filename' => 'core.colpkg',
            'content_type' => ['application/zip'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content_type']);

        $this->postJson('/api/study/imports', [
            'filename' => 'core.colpkg',
            'content_type' => 'text/plain',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content_type']);
    }

    public function test_store_blocks_active_imports(): void
    {
        $user = $this->signIn();
        StudyImportJob::factory()->processing()->for($user)->create();

        $this->postJson('/api/study/imports', [
            'filename' => 'core.colpkg',
        ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'active_study_import');
    }

    public function test_store_expires_stale_processing_imports_before_creating_a_new_session(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = $this->signIn();
        $stale = StudyImportJob::factory()->processing()->for($user)->create([
            'started_at' => now()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES + 1),
        ]);

        $response = $this->postJson('/api/study/imports', [
            'filename' => 'fresh.colpkg',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.import_job.status', StudyImportStatus::Pending->value);

        $this->assertSame(StudyImportStatus::Failed, $stale->refresh()->status);
        $this->assertSame('Study import timed out before completion.', $stale->error_message);
    }

    public function test_store_is_rate_limited_by_user(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        $this->withStudyImportRateLimitOverride(
            StudyImportRateLimiter::CREATE_NAME,
            [$user->id, $otherUser->id],
            function () use ($otherUser, $user): void {
                foreach ([1, 2] as $attempt) {
                    $response = $this
                        ->postJson('/api/study/imports', ['filename' => "core-{$attempt}.colpkg"])
                        ->assertCreated();

                    // Let the next create exercise the throttle bucket instead of the active-import guard.
                    StudyImportJob::query()
                        ->whereKey($response->json('data.import_job.id'))
                        ->update([
                            'status' => StudyImportStatus::Failed->value,
                            'completed_at' => now(),
                            'updated_at' => now(),
                        ]);
                }

                $this->signIn($otherUser);

                $this
                    ->postJson('/api/study/imports', ['filename' => 'other.colpkg'])
                    ->assertCreated();

                $this->signIn($user);

                $this
                    ->postJson('/api/study/imports', ['filename' => 'blocked.colpkg'])
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->getJson('/api/study/imports')
                    ->assertOk()
                    ->assertJsonCount(2, 'data');

                $this->assertSame(2, StudyImportJob::query()->where('user_id', $user->id)->count());
                $this->assertSame(1, StudyImportJob::query()->where('user_id', $otherUser->id)->count());
                $this->assertDatabaseMissing('study_import_jobs', [
                    'user_id' => $user->id,
                    'source_filename' => 'blocked.colpkg',
                ]);
            },
        );
    }
}
