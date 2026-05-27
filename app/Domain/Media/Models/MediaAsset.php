<?php

namespace App\Domain\Media\Models;

use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['disk', 'path', 'mime_type', 'size_bytes', 'checksum_sha256', 'original_filename'])]
class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory, HasUlids;

    protected static function newFactory(): MediaAssetFactory
    {
        return MediaAssetFactory::new();
    }
}
