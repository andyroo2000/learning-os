<?php

namespace App\Support\Content;

final class ConvoLabContentTables
{
    /** @var list<string> */
    public const TARGET_IN_DELETE_ORDER = [
        'content_episode_courses',
        'content_audio_script_renders',
        'content_audio_script_segments',
        'content_audio_script_media',
        'content_audio_scripts',
        'content_images',
        'content_sentences',
        'content_speakers',
        'content_dialogues',
        'content_episodes',
    ];
}
