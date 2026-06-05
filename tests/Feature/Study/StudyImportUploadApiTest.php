<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Http\Requests\Study\UploadStudyImportFileRequest;
use App\Jobs\ProcessStudyImportJob;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudyImportUploadApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_upload_requires_authentication(): void
    {
        $this->putImportUpload('/api/study/imports/'.strtolower((string) Str::ulid()).'/upload', 'anki bytes', 'application/zip')
            ->assertUnauthorized();
    }

    public function test_complete_requires_authentication(): void
    {
        $this->postJson('/api/study/imports/'.strtolower((string) Str::ulid()).'/complete')
            ->assertUnauthorized();
    }

    public function test_cancel_requires_authentication(): void
    {
        $this->postJson('/api/study/imports/'.strtolower((string) Str::ulid()).'/cancel')
            ->assertUnauthorized();
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
            ->assertJsonMissingPath('data.source_object_path');

        $importJob = StudyImportJob::query()->findOrFail($importJobId);
        $this->assertSame($user->id, $importJob->user_id);
        Storage::disk('study-imports')->assertExists($importJob->source_object_path);
        $this->assertSame($contents, Storage::disk('study-imports')->get($importJob->source_object_path));
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

    public function test_upload_request_rejects_non_digit_content_length_headers(): void
    {
        $this->assertInvalidContentLengthHeader('not-a-number');
    }

    public function test_upload_request_rejects_same_length_content_length_over_native_integer(): void
    {
        $this->assertInvalidContentLengthHeader($this->contentLengthAtNativeIntegerPlusOne());
    }

    public function test_upload_request_rejects_longer_content_length_over_native_integer(): void
    {
        $this->assertInvalidContentLengthHeader('1'.PHP_INT_MAX);
    }

    public function test_upload_request_accepts_native_integer_limit_content_length(): void
    {
        $request = $this->makeUploadRequestWithContentLength((string) PHP_INT_MAX);

        $request->validateResolved();

        $this->assertSame(PHP_INT_MAX, $request->contentSizeBytes());
    }

    public function test_upload_request_accepts_leading_zero_content_length(): void
    {
        $request = $this->makeUploadRequestWithContentLength('007');

        $request->validateResolved();

        $this->assertSame(7, $request->contentSizeBytes());
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

    public function test_complete_validates_the_uploaded_archive(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Queue::fake();
        Storage::fake('study-imports');
        $user = $this->signIn();
        $sourceObjectPath = 'study/imports/'.$user->id.'/complete/core.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, 'PK zipped bytes');
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $sourceObjectPath,
            'source_size_bytes' => null,
            'uploaded_at' => null,
            'upload_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/study/imports/'.strtoupper($importJob->id).'/complete')
            ->assertStatus(202)
            ->assertJsonPath('data.id', $importJob->id)
            ->assertJsonPath('data.status', StudyImportStatus::Pending->value)
            ->assertJsonPath('data.source_size_bytes', 15)
            ->assertJsonPath('data.uploaded_at', now()->toJSON())
            ->assertJsonMissingPath('data.source_object_path');

        Queue::assertPushedOn(
            ProcessStudyImportJob::QUEUE_NAME,
            ProcessStudyImportJob::class,
            fn (ProcessStudyImportJob $job): bool => $job->importJobId === $importJob->id,
        );
    }

    public function test_complete_does_not_enqueue_terminal_imports(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $importJob = StudyImportJob::factory()->completed()->for($user)->create();

        $this->postJson('/api/study/imports/'.$importJob->id.'/complete')
            ->assertOk()
            ->assertJsonPath('data.id', $importJob->id)
            ->assertJsonPath('data.status', StudyImportStatus::Completed->value);

        Queue::assertNotPushed(ProcessStudyImportJob::class);
    }

    public function test_complete_returns_ok_for_failed_terminal_imports_without_enqueuing(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $importJob = StudyImportJob::factory()->failed()->for($user)->create();

        $this->postJson('/api/study/imports/'.$importJob->id.'/complete')
            ->assertOk()
            ->assertJsonPath('data.id', $importJob->id)
            ->assertJsonPath('data.status', StudyImportStatus::Failed->value);

        Queue::assertNotPushed(ProcessStudyImportJob::class);
    }

    public function test_complete_hides_cross_user_import_jobs(): void
    {
        $this->signIn();
        $importJob = StudyImportJob::factory()->create();

        $this->postJson('/api/study/imports/'.$importJob->id.'/complete')
            ->assertNotFound();
    }

    public function test_complete_rejects_unfinished_expired_and_invalid_uploads(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = $this->signIn();

        $unfinished = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => 'study/imports/'.$user->id.'/unfinished/core.colpkg',
            'upload_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/study/imports/'.$unfinished->id.'/complete')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'study_import_upload_not_finished');

        $expiredPath = 'study/imports/'.$user->id.'/expired/core.colpkg';
        Storage::disk('study-imports')->put($expiredPath, 'PK zipped bytes');
        $expired = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $expiredPath,
            'upload_expires_at' => now()->subSecond(),
        ]);

        $this->postJson('/api/study/imports/'.$expired->id.'/complete')
            ->assertStatus(410)
            ->assertJsonPath('reason', 'study_import_upload_expired');
        $this->assertSame(StudyImportStatus::Failed, $expired->refresh()->status);
        Storage::disk('study-imports')->assertMissing($expiredPath);

        $invalidPath = 'study/imports/'.$user->id.'/invalid/core.colpkg';
        Storage::disk('study-imports')->put($invalidPath, 'NO zipped bytes');
        $invalid = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => $invalidPath,
            'upload_expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/study/imports/'.$invalid->id.'/complete')
            ->assertStatus(400)
            ->assertJsonPath('reason', 'invalid_study_import_archive');
        $this->assertSame(StudyImportStatus::Failed, $invalid->refresh()->status);
        Storage::disk('study-imports')->assertMissing($invalidPath);
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

    private function putImportUpload(
        string $url,
        string $contents,
        ?string $contentType,
        int|string|null $contentLength = null,
    ): TestResponse {
        $server = [
            'HTTP_ACCEPT' => 'application/json',
        ];

        if ($contentType !== null) {
            $server['CONTENT_TYPE'] = $contentType;
        }

        if ($contentLength !== null) {
            $server['CONTENT_LENGTH'] = $contentLength;
        }

        return $this->call('PUT', $url, [], [], [], $server, $contents);
    }

    private function makeUploadRequestWithContentLength(string $contentLength): UploadStudyImportFileRequest
    {
        $request = UploadStudyImportFileRequest::create(
            '/api/study/imports/'.strtolower((string) Str::ulid()).'/upload',
            'PUT',
            server: ['CONTENT_LENGTH' => $contentLength],
            content: 'anki bytes',
        );

        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);

        return $request;
    }

    private function assertInvalidContentLengthHeader(string $contentLength): void
    {
        $request = $this->makeUploadRequestWithContentLength($contentLength);

        try {
            $request->validateResolved();
            $this->fail('Expected malformed content length to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
            $this->assertSame(
                ['Study import upload content length is invalid.'],
                $exception->errors()['file'],
            );
        }
    }

    private function contentLengthAtNativeIntegerPlusOne(): string
    {
        $digits = str_split((string) PHP_INT_MAX);

        for ($index = count($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);

                return implode('', $digits);
            }

            $digits[$index] = '0';
        }

        return '1'.implode('', $digits);
    }
}
