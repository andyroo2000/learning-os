<?php

return [
    'gcs' => [
        'bucket' => env('GCS_BUCKET_NAME'),
    ],

    'avatars' => [
        'gcs_root' => env('AVATARS_GCS_ROOT', 'avatars'),
        'signed_urls_enabled' => env('AVATAR_SIGNED_URLS_ENABLED'),
        'signed_url_ttl_seconds' => env('AVATAR_SIGNED_URL_TTL_SECONDS', 12 * 60 * 60),
    ],

    'tool_audio' => [
        'gcs_root' => env('TOOLS_AUDIO_GCS_ROOT', 'tools-audio'),
        'signed_urls_enabled' => env('TOOLS_AUDIO_SIGNED_URLS_ENABLED'),
        'signed_url_ttl_seconds' => env('TOOLS_AUDIO_SIGNED_URL_TTL_SECONDS', 12 * 60 * 60),
        'rate_limit_window_ms' => env('TOOLS_AUDIO_SIGNED_URL_RATE_LIMIT_WINDOW_MS', 60 * 1000),
        'rate_limit_max_requests' => env('TOOLS_AUDIO_SIGNED_URL_RATE_LIMIT_MAX_REQUESTS', 120),
    ],
];
