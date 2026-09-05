<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;

class CreateMediaAssetMimeTypeValidationActionTest extends CreateMediaAssetActionTestCase
{
    public function test_it_rejects_blank_mime_type(): void
    {
        $this->assertMediaAssetInputRejected(['mimeType' => '   '], 'Media asset MIME type is required.');
    }

    public function test_it_rejects_mime_type_longer_than_column_limit(): void
    {
        $this->assertMediaAssetInputRejected(
            ['mimeType' => 'image/'.str_repeat('a', MediaAsset::MAX_MIME_TYPE_LENGTH)],
            'Media asset MIME type must not exceed '.MediaAsset::MAX_MIME_TYPE_LENGTH.' characters.',
        );
    }

    public function test_it_rejects_invalid_mime_type(): void
    {
        $this->assertMediaAssetInputRejected(
            ['mimeType' => 'image'],
            'Media asset MIME type must include a type and subtype.',
        );
    }

    public function test_it_rejects_mime_type_without_a_subtype(): void
    {
        $this->assertMediaAssetInputRejected(
            ['mimeType' => 'image/'],
            'Media asset MIME type must include a type and subtype.',
        );
    }

    public function test_it_rejects_mime_type_without_a_type(): void
    {
        $this->assertMediaAssetInputRejected(
            ['mimeType' => '/jpeg'],
            'Media asset MIME type must include a type and subtype.',
        );
    }

    public function test_it_rejects_mime_type_with_extra_parts(): void
    {
        $this->assertMediaAssetInputRejected(
            ['mimeType' => 'image/jpeg/extra'],
            'Media asset MIME type must include a type and subtype.',
        );
    }
}
