<?php

namespace Tests\Unit\Http\Resources\Study;

use App\Domain\Study\Models\StudyImportJob;
use App\Http\Resources\Study\StudyOverviewCompatibilityResource;
use Illuminate\Http\Request;
use Tests\TestCase;

class StudyOverviewCompatibilityResourceTest extends TestCase
{
    public function test_it_uses_convolab_millisecond_timestamps_for_the_latest_import(): void
    {
        $import = new StudyImportJob;
        $import->setRawAttributes([
            'started_at' => '2026-07-18 12:34:56.123456',
            'uploaded_at' => '2026-07-18 12:35:56.234567',
            'upload_expires_at' => '2026-07-18 13:35:56.345678',
            'completed_at' => '2026-07-18 12:36:56.456789',
            'created_at' => '2026-07-18 12:33:56.567891',
            'updated_at' => '2026-07-18 12:37:56.678912',
        ]);

        $payload = (new StudyOverviewCompatibilityResource([
            'latest_import' => $import,
        ]))->toArray(new Request);

        $this->assertSame('2026-07-18T12:34:56.123Z', $payload['latestImport']['startedAt']);
        $this->assertSame('2026-07-18T12:35:56.234Z', $payload['latestImport']['uploadedAt']);
        $this->assertSame('2026-07-18T13:35:56.345Z', $payload['latestImport']['uploadExpiresAt']);
        $this->assertSame('2026-07-18T12:36:56.456Z', $payload['latestImport']['completedAt']);
        $this->assertSame('2026-07-18T12:33:56.567Z', $payload['latestImport']['createdAt']);
        $this->assertSame('2026-07-18T12:37:56.678Z', $payload['latestImport']['updatedAt']);
    }
}
