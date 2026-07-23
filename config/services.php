<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'wanikani' => [
        'base_url' => env('WANIKANI_API_BASE_URL', 'https://api.wanikani.com/v2'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env(
            'GOOGLE_REDIRECT_URI',
            rtrim((string) env('APP_URL', 'http://localhost'), '/')
                .'/api/convolab/browser/auth/google/callback',
        ),
    ],

    'convolab' => [
        'proxy_user_email' => env('CONVOLAB_PROXY_USER_EMAIL'),
        'client_url' => env('CONVOLAB_CLIENT_URL', 'http://localhost:5173'),
        'admin_emails' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CONVOLAB_ADMIN_EMAILS', '')),
        ))),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_API_BASE_URL', 'https://api.openai.com/v1'),
        'study_card_model' => env('STUDY_CARD_GENERATOR_MODEL', 'gpt-5.5'),
        'study_card_reasoning_effort' => env('STUDY_CARD_GENERATOR_REASONING_EFFORT', 'medium'),
        'content_model' => env('CORE_GENERATOR_MODEL', 'gpt-5.5'),
        'content_reasoning_effort' => env('CORE_GENERATOR_REASONING_EFFORT', 'medium'),
        'daily_audio_model' => env('DAILY_AUDIO_GENERATOR_MODEL', 'gpt-5.5'),
        'daily_audio_reasoning_effort' => env('DAILY_AUDIO_GENERATOR_REASONING_EFFORT', 'medium'),
        'study_image_model' => env('STUDY_CARD_IMAGE_GENERATOR_MODEL', 'gpt-image-1'),
        'pitch_accent_model' => env('PITCH_ACCENT_READING_MODEL', 'gpt-5.4-mini'),
        'pitch_accent_reasoning_effort' => env('PITCH_ACCENT_READING_REASONING_EFFORT', 'low'),
    ],

    'fish_audio' => [
        'api_key' => env('FISH_AUDIO_API_KEY'),
        'base_url' => env('FISH_AUDIO_API_BASE_URL', 'https://api.fish.audio'),
        'backend' => env('FISH_AUDIO_BACKEND', 's1'),
    ],

];
