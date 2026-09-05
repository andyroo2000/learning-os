<?php

namespace Tests\Feature\Study;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Study\Concerns\MakesStudyImportUploadRequests;
use Tests\TestCase;

class StudyImportUploadAccessApiTest extends TestCase
{
    use MakesStudyImportUploadRequests;
    use RefreshDatabase;

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
}
