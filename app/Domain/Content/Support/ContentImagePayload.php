<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Models\ContentImage;
use App\Support\DateTime\ConvoLabTimestamp;

final class ContentImagePayload
{
    /** @return array{id: string, episodeId: string, url: string, prompt: string, order: int, sentenceStartId: ?string, sentenceEndId: ?string, createdAt: string} */
    public static function fromModel(ContentImage $image): array
    {
        return [
            'id' => $image->id,
            'episodeId' => $image->episode_id,
            'url' => $image->url,
            'prompt' => $image->prompt,
            'order' => $image->sort_order,
            'sentenceStartId' => $image->sentence_start_id,
            'sentenceEndId' => $image->sentence_end_id,
            'createdAt' => ConvoLabTimestamp::serialize($image->created_at),
        ];
    }
}
