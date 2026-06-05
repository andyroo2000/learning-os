<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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

    private function putImportUpload(
        string $url,
        string $contents,
        ?string $contentType,
        ?int $contentLength = null,
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
}
