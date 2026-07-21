<?php

namespace App\Support\Content;

final class ConvoLabContentTables
{
    /** @var list<string> */
    public const CONTENT_IN_DELETE_ORDER = [
        'content_course_core_items',
        'content_episode_courses',
        'content_courses',
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

    /** @var list<string> */
    public const RESET_IN_DELETE_ORDER = [
        'content_episode_tombstones',
        ...self::CONTENT_IN_DELETE_ORDER,
    ];

    /** @var list<string> */
    public const IMPORT_OWNERSHIP_TABLES = [
        'content_episodes',
        'content_courses',
        'content_audio_script_media',
        'content_episode_courses',
    ];

    /** @var list<string> */
    public const IMPORTED_ROOTS_IN_DELETE_ORDER = [
        'content_episode_courses',
        'content_courses',
        'content_episodes',
        'content_audio_script_media',
    ];
}
