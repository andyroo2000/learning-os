<?php

namespace Tests\Unit\Resources\Content;

use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentEpisode;
use App\Http\Resources\Content\ContentEpisodeLibraryResource;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class ContentEpisodeLibraryResourceTest extends TestCase
{
    public function test_dialogue_turn_count_falls_back_to_a_loaded_sentence_collection(): void
    {
        $dialogue = new ContentDialogue;
        $dialogue->setRelation('speakers', new Collection);
        $dialogue->setRelation('sentences', new Collection([
            new \stdClass,
            new \stdClass,
            new \stdClass,
        ]));

        $episode = new ContentEpisode;
        $episode->setRelation('dialogue', $dialogue);

        $resource = (new ContentEpisodeLibraryResource($episode))->resolve(request());

        $this->assertSame(3, $resource['dialogue']['turnCount']);
        $this->assertIsInt($resource['dialogue']['turnCount']);
        $this->assertArrayNotHasKey('sentences', $resource['dialogue']);
    }
}
