<?php

return [
    'disk' => env('DAILY_AUDIO_DISK', 'media'),
    'l1_voice_id' => env(
        'DAILY_AUDIO_L1_VOICE_ID',
        'fishaudio:ac934b39586e475b83f3277cd97b5cd4',
    ),
    'l2_voice_id' => env(
        'DAILY_AUDIO_L2_VOICE_ID',
        'fishaudio:abb4362e736f40b7b5716f4fafcafa9f',
    ),
    'l2_secondary_voice_id' => env(
        'DAILY_AUDIO_L2_SECONDARY_VOICE_ID',
        'fishaudio:0dff3f6860294829b98f8c4501b2cf25',
    ),
];
