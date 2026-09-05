<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;

class CreateMediaAssetMetadataValidationActionTest extends CreateMediaAssetActionTestCase
{
    public function test_it_rejects_original_filename_longer_than_column_limit(): void
    {
        $this->assertMediaAssetInputRejected(
            ['originalFilename' => str_repeat('a', MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH + 1)],
            'Media asset original filename must not exceed '.MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH.' characters.',
        );
    }
}
