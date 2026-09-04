<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Models\ContentAudioScriptSegment;

final readonly class GeneratedContentAudioScriptImage
{
    public function __construct(
        public ClaimedContentAudioScriptGeneration $claim,
        public ContentAudioScriptSegment $segment,
        public string $mediaId,
        public string $path,
    ) {}
}
