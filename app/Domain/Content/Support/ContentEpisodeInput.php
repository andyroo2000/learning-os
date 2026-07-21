<?php

namespace App\Domain\Content\Support;

final class ContentEpisodeInput
{
    public const AUDIO_SPEEDS = ['slow', 'medium', 'normal'];

    public const JLPT_LEVELS = ['N5', 'N4', 'N3', 'N2', 'N1'];

    public const STATUSES = ['draft', 'generating', 'ready', 'error'];
}
