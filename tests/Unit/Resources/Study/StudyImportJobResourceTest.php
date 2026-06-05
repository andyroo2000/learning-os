<?php

namespace Tests\Unit\Resources\Study;

use App\Domain\Study\Models\StudyImportJob;
use App\Http\Resources\Study\StudyImportJobResource;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class StudyImportJobResourceTest extends TestCase
{
    public function test_resource_preserves_raw_legacy_status_values(): void
    {
        $importJob = new StudyImportJob;
        $importJob->setRawAttributes([
            'id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'status' => 'paused',
            'source_type' => 'anki_colpkg',
            'source_filename' => 'legacy.colpkg',
            'source_object_path' => 'study/imports/user/import/legacy.colpkg',
            'source_size_bytes' => null,
            'deck_name' => 'Legacy',
            'preview_json' => json_encode(['card_count' => 0], JSON_THROW_ON_ERROR),
            'summary_json' => null,
        ], sync: true);

        $payload = StudyImportJobResource::make($importJob)->toArray(new Request);

        $this->assertSame('paused', $payload['status']);
        $this->assertSame(['card_count' => 0], $payload['preview']);
        $this->assertSame(null, $payload['summary']);
        $this->assertArrayNotHasKey('source_object_path', $payload);
    }
}
