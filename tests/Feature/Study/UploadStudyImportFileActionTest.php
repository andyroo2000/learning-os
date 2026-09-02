<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\UploadStudyImportFileAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Exceptions\StudyImportConflictException;
use App\Domain\Study\Exceptions\StudyImportUploadExpiredException;
use App\Domain\Study\Exceptions\StudyImportValidationException;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\Study\AssertsMalformedStudyImportJobIds;
use Tests\TestCase;

class UploadStudyImportFileActionTest extends TestCase
{
    use AssertsMalformedStudyImportJobIds, RefreshDatabase;

    public function test_upload_stores_file_and_normalizes_the_import_job_id(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => StudyImportJob::SOURCE_UPLOAD_FOLDER.'/'.$user->id.'/upload/core.colpkg',
            'source_content_type' => 'application/zip',
            'upload_expires_at' => now()->addHour(),
        ]);

        $uploaded = app(UploadStudyImportFileAction::class)->handle(
            userId: $user->id,
            importJobId: '  '.strtoupper($importJob->id).'  ',
            contents: 'anki bytes',
            contentType: ' APPLICATION/ZIP ',
        );

        $this->assertSame($importJob->id, $uploaded->id);
        $this->assertSame(10, $uploaded->source_size_bytes);
        $this->assertSame(now()->toJSON(), $uploaded->uploaded_at->toJSON());
        Storage::disk('study-imports')->assertExists($importJob->source_object_path);
        $this->assertSame('anki bytes', Storage::disk('study-imports')->get($importJob->source_object_path));
    }

    public function test_upload_streams_file_bytes_and_records_the_actual_size(): void
    {
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => StudyImportJob::SOURCE_UPLOAD_FOLDER.'/'.$user->id.'/stream/core.colpkg',
            'source_content_type' => 'application/zip',
        ]);
        $contents = fopen('php://temp', 'w+b');
        $this->assertIsResource($contents);
        fwrite($contents, 'streamed anki bytes');
        rewind($contents);

        try {
            $uploaded = app(UploadStudyImportFileAction::class)->handle(
                userId: $user->id,
                importJobId: $importJob->id,
                contents: $contents,
                contentType: 'application/zip',
                contentSizeBytes: 19,
            );
        } finally {
            fclose($contents);
        }

        $this->assertSame(19, $uploaded->source_size_bytes);
        $this->assertSame(
            'streamed anki bytes',
            Storage::disk('study-imports')->get($importJob->source_object_path),
        );
    }

    #[DataProvider('failedUploadWriteProvider')]
    public function test_upload_rejects_storage_write_failures_without_recording_or_retaining_partial_bytes(
        string $pathSegment,
        bool $throwDuringWrite,
        ?string $expectedPreviousMessage,
    ): void {
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => StudyImportJob::SOURCE_UPLOAD_FOLDER.'/'.$user->id.'/'.$pathSegment.'/core.colpkg',
            'source_content_type' => 'application/zip',
            'source_size_bytes' => null,
            'uploaded_at' => null,
        ]);
        $sourceDisk = Storage::disk('study-imports');
        $this->installFailingStudyImportUploadDisk($sourceDisk, throwDuringWrite: $throwDuringWrite);

        try {
            $this->upload($importJob, contents: 'complete archive bytes');
            $this->fail('Expected a failed storage write to reject the upload.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to store the study import upload.', $exception->getMessage());
            $this->assertSame($expectedPreviousMessage, $exception->getPrevious()?->getMessage());
        }

        $importJob->refresh();

        $this->assertSame(StudyImportStatus::Pending, $importJob->status);
        $this->assertNull($importJob->source_size_bytes);
        $this->assertNull($importJob->uploaded_at);
        $sourceDisk->assertMissing($importJob->source_object_path);
    }

    /**
     * @return array<string, array{string, bool, ?string}>
     */
    public static function failedUploadWriteProvider(): array
    {
        return [
            'adapter returns false' => ['failed-write', false, null],
            'adapter throws' => ['thrown-write', true, 'Simulated storage transport failure.'],
        ];
    }

    #[DataProvider('failedUploadPartialCleanupFailureProvider')]
    public function test_upload_preserves_the_storage_failure_when_partial_cleanup_fails(bool $throwDuringDelete): void
    {
        Exceptions::fake();
        Storage::fake('study-imports');
        $user = User::factory()->create();
        $importJob = StudyImportJob::factory()->for($user)->create([
            'source_object_path' => StudyImportJob::SOURCE_UPLOAD_FOLDER.'/'.$user->id.'/failed-cleanup/core.colpkg',
            'source_content_type' => 'application/zip',
            'source_size_bytes' => null,
            'uploaded_at' => null,
        ]);
        $sourceDisk = Storage::disk('study-imports');
        $this->installFailingStudyImportUploadDisk($sourceDisk, throwDuringDelete: $throwDuringDelete);

        try {
            app(UploadStudyImportFileAction::class)->handle(
                userId: $user->id,
                importJobId: $importJob->id,
                contents: 'complete archive bytes',
                contentType: 'application/zip',
            );
            $this->fail('Expected a failed storage write to reject the upload.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to store the study import upload.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $importJob->refresh();

        $this->assertSame(StudyImportStatus::Pending, $importJob->status);
        $this->assertNull($importJob->source_size_bytes);
        $this->assertNull($importJob->uploaded_at);
        $sourceDisk->assertExists($importJob->source_object_path);
        Exceptions::assertReported(function (RuntimeException $exception) use ($importJob, $throwDuringDelete): bool {
            if ($exception->getMessage() !== 'Unable to remove a partial study import upload: '.$importJob->source_object_path) {
                return false;
            }

            return $throwDuringDelete
                ? $exception->getPrevious()?->getMessage() === 'Simulated partial upload cleanup failure.'
                : $exception->getPrevious() === null;
        });
        Exceptions::assertReportedCount(1);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function failedUploadPartialCleanupFailureProvider(): array
    {
        return [
            'adapter returns false' => [false],
            'adapter throws' => [true],
        ];
    }

    public function test_upload_rejects_actual_stream_bytes_above_the_limit_before_staging_the_overflow(): void
    {
        $stagedContents = tmpfile();
        $this->assertIsResource($stagedContents);
        $actualContentSizeBytes = StudyImportJob::MAX_ASYNC_IMPORT_BYTES;
        $appendChunk = new ReflectionMethod(UploadStudyImportFileAction::class, 'appendChunk');

        try {
            $appendChunk->invokeArgs(
                app(UploadStudyImportFileAction::class),
                [$stagedContents, 'x', &$actualContentSizeBytes],
            );
            $this->fail('Expected actual upload bytes above the limit to be rejected.');
        } catch (StudyImportValidationException $exception) {
            $this->assertSame('file', $exception->field());
            $this->assertSame(0, fstat($stagedContents)['size']);
        } finally {
            fclose($stagedContents);
        }
    }

    public function test_upload_hides_cross_user_import_jobs(): void
    {
        $importJob = StudyImportJob::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        $this->upload($importJob, userId: User::factory()->create()->id);
    }

    public function test_upload_hides_malformed_import_job_ids_without_querying_import_jobs(): void
    {
        Storage::fake('study-imports');
        $userId = User::factory()->create()->id;

        $queries = $this->captureQueriesForExpectedMalformedImportJobNotFound(function () use ($userId): void {
            app(UploadStudyImportFileAction::class)->handle(
                userId: $userId,
                importJobId: 'not-a-ulid',
                contents: 'anki bytes',
                contentType: 'application/zip',
            );
        });

        $this->assertNoStudyImportJobsQueried($queries);
        $this->assertSame([], Storage::disk('study-imports')->allFiles());
    }

    public function test_upload_hides_malformed_import_job_ids_without_echoing_the_id(): void
    {
        Storage::fake('study-imports');

        try {
            app(UploadStudyImportFileAction::class)->handle(
                userId: User::factory()->create()->id,
                importJobId: 'not-a-ulid',
                contents: 'anki bytes',
                contentType: 'application/zip',
            );
            $this->fail('Expected malformed import job IDs to be hidden as not found.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(StudyImportJob::class, $exception->getModel());
            $this->assertSame([], $exception->getIds());
        }
    }

    public function test_upload_rejects_non_pending_imports(): void
    {
        $importJob = StudyImportJob::factory()->completed()->create();

        $this->expectException(StudyImportConflictException::class);

        $this->upload($importJob);
    }

    public function test_upload_marks_expired_sessions_failed(): void
    {
        Carbon::setTestNow('2026-06-05 12:00:00');
        $importJob = StudyImportJob::factory()->create([
            'source_object_path' => 'study/imports/1/expired/core.colpkg',
            'upload_expires_at' => now()->subSecond(),
        ]);

        $this->expectException(StudyImportUploadExpiredException::class);

        try {
            app(UploadStudyImportFileAction::class)->handle(
                userId: $importJob->user_id,
                importJobId: $importJob->id,
                contents: 'anki bytes',
                contentType: $importJob->source_content_type,
            );
        } finally {
            $this->assertSame(StudyImportStatus::Failed, $importJob->refresh()->status);
            $this->assertSame('Study import upload session has expired.', $importJob->error_message);
            $this->assertSame(now()->toJSON(), $importJob->completed_at->toJSON());
        }
    }

    public function test_upload_rejects_mismatched_content_type_and_oversized_uploads(): void
    {
        Storage::fake('study-imports');
        $importJob = StudyImportJob::factory()->create([
            'source_object_path' => 'study/imports/1/core/core.colpkg',
            'source_content_type' => 'application/zip',
        ]);
        $action = app(UploadStudyImportFileAction::class);

        try {
            $action->handle(
                userId: $importJob->user_id,
                importJobId: $importJob->id,
                contents: 'anki bytes',
                contentType: 'application/octet-stream',
            );
            $this->fail('Expected mismatched content type to be rejected.');
        } catch (StudyImportValidationException $exception) {
            $this->assertSame('content_type', $exception->field());
        }

        try {
            $action->handle(
                userId: $importJob->user_id,
                importJobId: $importJob->id,
                contents: 'tiny',
                contentType: 'application/zip',
                contentSizeBytes: StudyImportJob::MAX_ASYNC_IMPORT_BYTES + 1,
            );
            $this->fail('Expected oversized uploads to be rejected.');
        } catch (StudyImportValidationException $exception) {
            $this->assertSame('file', $exception->field());
        }

        Storage::disk('study-imports')->assertMissing($importJob->source_object_path);
    }

    public function test_upload_rejects_mismatched_declared_content_size(): void
    {
        Storage::fake('study-imports');
        $importJob = StudyImportJob::factory()->create([
            'source_object_path' => 'study/imports/1/mismatched-size/core.colpkg',
            'source_content_type' => 'application/zip',
        ]);
        $originalSizeBytes = $importJob->source_size_bytes;

        try {
            app(UploadStudyImportFileAction::class)->handle(
                userId: $importJob->user_id,
                importJobId: $importJob->id,
                contents: 'anki bytes',
                contentType: 'application/zip',
                contentSizeBytes: 11,
            );
            $this->fail('Expected mismatched declared content size to be rejected.');
        } catch (StudyImportValidationException $exception) {
            $this->assertSame('file', $exception->field());
        }

        $importJob->refresh();

        $this->assertSame($originalSizeBytes, $importJob->source_size_bytes);
        $this->assertNull($importJob->uploaded_at);
        Storage::disk('study-imports')->assertMissing($importJob->source_object_path);
    }

    public function test_upload_rejects_declared_content_size_over_the_limit(): void
    {
        Storage::fake('study-imports');
        $importJob = StudyImportJob::factory()->create([
            'source_object_path' => 'study/imports/1/declared-oversized/core.colpkg',
            'source_content_type' => 'application/zip',
        ]);

        try {
            app(UploadStudyImportFileAction::class)->handle(
                userId: $importJob->user_id,
                importJobId: $importJob->id,
                contents: 'anki bytes',
                contentType: 'application/zip',
                contentSizeBytes: StudyImportJob::MAX_ASYNC_IMPORT_BYTES + 1,
            );
            $this->fail('Expected oversized declared content size to be rejected.');
        } catch (StudyImportValidationException $exception) {
            $this->assertSame('file', $exception->field());
            $this->assertSame(
                'Study import upload must not exceed '.StudyImportJob::MAX_ASYNC_IMPORT_BYTES.' bytes.',
                $exception->getMessage(),
            );
        }

        Storage::disk('study-imports')->assertMissing($importJob->source_object_path);
    }

    private function installFailingStudyImportUploadDisk(
        FilesystemAdapter $inner,
        bool $throwDuringWrite = false,
        ?bool $throwDuringDelete = null,
    ): void {
        Storage::set('study-imports', new class($inner, $throwDuringWrite, $throwDuringDelete) extends FilesystemAdapter
        {
            public function __construct(
                private readonly FilesystemAdapter $inner,
                private readonly bool $throwDuringWrite,
                private readonly ?bool $throwDuringDelete,
            ) {
                parent::__construct($inner->getDriver(), $inner->getAdapter(), $inner->getConfig());
            }

            public function writeStream($path, $resource, array $options = [])
            {
                $this->inner->put($path, 'partial archive bytes');

                if ($this->throwDuringWrite) {
                    throw new RuntimeException('Simulated storage transport failure.');
                }

                return false;
            }

            public function delete($paths): bool
            {
                if ($this->throwDuringDelete === true) {
                    throw new RuntimeException('Simulated partial upload cleanup failure.');
                }

                return $this->throwDuringDelete === false
                    ? false
                    : $this->inner->delete($paths);
            }
        });
    }

    private function upload(
        StudyImportJob $importJob,
        ?int $userId = null,
        string $contents = 'anki bytes',
    ): StudyImportJob {
        return app(UploadStudyImportFileAction::class)->handle(
            userId: $userId ?? $importJob->user_id,
            importJobId: $importJob->id,
            contents: $contents,
            contentType: $importJob->source_content_type,
        );
    }
}
