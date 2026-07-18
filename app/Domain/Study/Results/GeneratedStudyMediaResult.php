<?php

namespace App\Domain\Study\Results;

use App\Domain\Media\Models\MediaAsset;

final readonly class GeneratedStudyMediaResult
{
    /**
     * @param  array{id: string, filename: string, url: string, mediaKind: 'audio'|'image', source: 'generated'}  $mediaRef
     */
    public function __construct(
        public MediaAsset $mediaAsset,
        public array $mediaRef,
    ) {}
}
