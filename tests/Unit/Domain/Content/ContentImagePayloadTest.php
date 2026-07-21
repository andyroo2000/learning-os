<?php

namespace Tests\Unit\Domain\Content;

use App\Domain\Content\Models\ContentImage;
use App\Domain\Content\Support\ContentImagePayload;
use App\Http\Resources\Content\ContentImageResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentImagePayloadTest extends TestCase
{
    public function test_domain_jobs_and_http_resources_share_the_exact_compatibility_shape(): void
    {
        $image = new ContentImage;
        $image->id = (string) Str::uuid();
        $image->episode_id = (string) Str::uuid();
        $image->url = 'https://example.test/image.png';
        $image->prompt = 'A train entering a mountain station.';
        $image->sort_order = 2;
        $image->sentence_start_id = (string) Str::uuid();
        $image->sentence_end_id = null;
        $image->created_at = Carbon::parse('2026-07-21T15:00:00.123456Z');

        $payload = ContentImagePayload::fromModel($image);

        $this->assertSame([
            'id' => $image->id,
            'episodeId' => $image->episode_id,
            'url' => 'https://example.test/image.png',
            'prompt' => 'A train entering a mountain station.',
            'order' => 2,
            'sentenceStartId' => $image->sentence_start_id,
            'sentenceEndId' => null,
            'createdAt' => '2026-07-21T15:00:00.000Z',
        ], $payload);
        $this->assertSame($payload, (new ContentImageResource($image))->resolve(request()));
    }
}
