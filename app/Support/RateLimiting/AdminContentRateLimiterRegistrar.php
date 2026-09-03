<?php

namespace App\Support\RateLimiting;

use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Domain\Analytics\Support\ToolAnalyticsRateLimiter;
use App\Domain\Content\Support\ContentAudioRateLimiter;
use App\Domain\Content\Support\ContentAudioScriptRateLimiter;
use App\Domain\Content\Support\ContentCourseRateLimiter;
use App\Domain\Content\Support\ContentDialogueRateLimiter;
use App\Domain\Content\Support\ContentEpisodeRateLimiter;
use App\Domain\Content\Support\ContentImageRateLimiter;
use App\Domain\Courses\Support\CourseRateLimiter;
use App\Domain\FeatureFlags\Support\FeatureFlagUpdateRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class AdminContentRateLimiterRegistrar
{
    public static function register(): void
    {
        self::registerAdminMutationRateLimiters();
        self::registerContentResourceRateLimiters();
        self::registerContentGenerationRateLimiters();
        self::registerCourseAndFeatureRateLimiters();
    }

    private static function registerAdminMutationRateLimiters(): void
    {
        foreach ([
            AdminMutationRateLimiter::USER_DELETE,
            AdminMutationRateLimiter::INVITE_CREATE,
            AdminMutationRateLimiter::INVITE_DELETE,
            AdminMutationRateLimiter::PRONUNCIATION_DICTIONARY_UPDATE,
            AdminMutationRateLimiter::SPEAKER_AVATAR_UPLOAD,
            AdminMutationRateLimiter::SPEAKER_AVATAR_RECROP,
            AdminMutationRateLimiter::USER_AVATAR_UPLOAD,
            AdminMutationRateLimiter::SCRIPT_LAB_COURSE_CREATE,
            AdminMutationRateLimiter::SCRIPT_LAB_COURSE_DELETE,
            AdminMutationRateLimiter::SENTENCE_SCRIPT_GENERATE,
            AdminMutationRateLimiter::SENTENCE_SCRIPT_DELETE,
            AdminMutationRateLimiter::SCRIPT_LAB_LINE_SYNTHESIZE,
            AdminMutationRateLimiter::SCRIPT_LAB_PRONUNCIATION_TEST,
            AdminMutationRateLimiter::COURSE_PIPELINE_UPDATE,
            AdminMutationRateLimiter::COURSE_DIALOGUE_GENERATE,
            AdminMutationRateLimiter::COURSE_SCRIPT_GENERATE,
            AdminMutationRateLimiter::COURSE_AUDIO_GENERATE,
            AdminMutationRateLimiter::COURSE_LINE_SYNTHESIZE,
            AdminMutationRateLimiter::COURSE_LINE_DELETE,
        ] as $name) {
            RateLimiter::for(
                $name,
                fn (Request $request): Limit => AdminMutationRateLimiter::limit($name, $request),
            );
        }
    }

    private static function registerContentResourceRateLimiters(): void
    {
        foreach ([
            ContentEpisodeRateLimiter::CREATE_NAME => ContentEpisodeRateLimiter::create(),
            ContentEpisodeRateLimiter::UPDATE_NAME => ContentEpisodeRateLimiter::update(),
            ContentEpisodeRateLimiter::DELETE_NAME => ContentEpisodeRateLimiter::delete(),
        ] as $name => $limiter) {
            RateLimiter::for($name, fn (Request $request): Limit => $limiter->limit($request));
        }

        foreach ([
            ContentCourseRateLimiter::CREATE_NAME => ContentCourseRateLimiter::forCreate(),
            ContentCourseRateLimiter::UPDATE_NAME => ContentCourseRateLimiter::forUpdate(),
            ContentCourseRateLimiter::DELETE_NAME => ContentCourseRateLimiter::forDelete(),
            ContentCourseRateLimiter::GENERATION_NAME => ContentCourseRateLimiter::forGeneration(),
            ContentCourseRateLimiter::RESET_NAME => ContentCourseRateLimiter::forReset(),
        ] as $name => $limiter) {
            RateLimiter::for($name, fn (Request $request): Limit => $limiter->limit($request));
        }
    }

    private static function registerContentGenerationRateLimiters(): void
    {
        RateLimiter::for(
            ContentDialogueRateLimiter::GENERATION_NAME,
            fn (Request $request): Limit => ContentDialogueRateLimiter::generation($request),
        );
        RateLimiter::for(
            ContentImageRateLimiter::GENERATION_NAME,
            fn (Request $request): Limit => ContentImageRateLimiter::generation($request),
        );
        RateLimiter::for(
            ContentAudioRateLimiter::GENERATION_NAME,
            fn (Request $request): Limit => ContentAudioRateLimiter::generation($request),
        );
        RateLimiter::for(
            ContentAudioScriptRateLimiter::GENERATION_NAME,
            fn (Request $request): Limit => ContentAudioScriptRateLimiter::generation($request),
        );
        RateLimiter::for(
            ContentAudioScriptRateLimiter::UPDATE_NAME,
            fn (Request $request): Limit => ContentAudioScriptRateLimiter::update($request),
        );
        RateLimiter::for(
            ContentAudioScriptRateLimiter::MEDIA_READ_NAME,
            fn (Request $request): Limit => ContentAudioScriptRateLimiter::mediaRead($request),
        );
    }

    private static function registerCourseAndFeatureRateLimiters(): void
    {
        $courseCreateRateLimiter = CourseRateLimiter::create();
        RateLimiter::for(CourseRateLimiter::CREATE_NAME, function (Request $request) use ($courseCreateRateLimiter): Limit {
            return $courseCreateRateLimiter->limit($request);
        });

        $courseUpdateRateLimiter = CourseRateLimiter::update();
        RateLimiter::for(CourseRateLimiter::UPDATE_NAME, function (Request $request) use ($courseUpdateRateLimiter): Limit {
            return $courseUpdateRateLimiter->limit($request);
        });

        $courseDeleteRateLimiter = CourseRateLimiter::delete();
        RateLimiter::for(CourseRateLimiter::DELETE_NAME, function (Request $request) use ($courseDeleteRateLimiter): Limit {
            return $courseDeleteRateLimiter->limit($request);
        });

        RateLimiter::for(
            FeatureFlagUpdateRateLimiter::NAME,
            fn (Request $request): Limit => FeatureFlagUpdateRateLimiter::limit($request),
        );
        RateLimiter::for(
            ToolAnalyticsRateLimiter::NAME,
            fn (Request $request): Limit => ToolAnalyticsRateLimiter::limit($request),
        );
        RateLimiter::for(
            ToolAnalyticsRateLimiter::BROWSER_NAME,
            fn (Request $request): Limit => ToolAnalyticsRateLimiter::browserLimit($request),
        );
    }
}
