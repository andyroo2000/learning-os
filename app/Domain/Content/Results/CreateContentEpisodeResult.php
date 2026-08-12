<?php

namespace App\Domain\Content\Results;

use App\Domain\Content\Models\ContentEpisode;

final readonly class CreateContentEpisodeResult
{
    private function __construct(public ContentEpisode $episode, public bool $wasCreated) {}

    public static function created(ContentEpisode $episode): self
    {
        return new self($episode, true);
    }

    public static function existing(ContentEpisode $episode): self
    {
        return new self($episode, false);
    }
}
