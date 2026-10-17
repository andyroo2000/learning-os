<?php

namespace App\Support\RateLimiting;

use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Domain\Analytics\Support\ToolAnalyticsRateLimiter;
use App\Domain\Content\Support\ContentAudioScriptRateLimiter;
use App\Domain\Content\Support\ContentCourseRateLimiter;
use App\Domain\Content\Support\ContentEpisodeRateLimiter;
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
        self::registerContentMediaRateLimiter();
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
            ContentEpisodeRateLimiter::UPDATE_NAME => ContentEpisodeRateLimiter::update(),
            ContentEpisodeRateLimiter::DELETE_NAME => ContentEpisodeRateLimiter::delete(),
        ] as $name => $limiter) {
            RateLimiter::for($name, fn (Request $request): Limit => $limiter->limit($request));
        }

        foreach ([
            ContentCourseRateLimiter::UPDATE_NAME => ContentCourseRateLimiter::forUpdate(),
            ContentCourseRateLimiter::DELETE_NAME => ContentCourseRateLimiter::forDelete(),
        ] as $name => $limiter) {
            RateLimiter::for($name, fn (Request $request): Limit => $limiter->limit($request));
        }
    }

    private static function registerContentMediaRateLimiter(): void
    {
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
