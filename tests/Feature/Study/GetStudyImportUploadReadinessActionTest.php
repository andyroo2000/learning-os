<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\GetStudyImportUploadReadinessAction;
use Tests\TestCase;

class GetStudyImportUploadReadinessActionTest extends TestCase
{
    public function test_it_reports_study_import_uploads_ready_when_storage_is_configured(): void
    {
        $readiness = app(GetStudyImportUploadReadinessAction::class)->handle();

        $this->assertTrue($readiness['ready']);
        $this->assertNull($readiness['message']);
    }

    public function test_it_reports_study_import_uploads_unavailable_when_storage_is_not_configured(): void
    {
        config()->offsetUnset('filesystems.disks.study-imports');

        $readiness = app(GetStudyImportUploadReadinessAction::class)->handle();

        $this->assertFalse($readiness['ready']);
        $this->assertSame(
            'Study import uploads are temporarily unavailable because storage is not configured.',
            $readiness['message'],
        );
    }
}
