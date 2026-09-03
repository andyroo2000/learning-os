<?php

namespace App\Support\RateLimiting;

use App\Domain\Achievements\Support\AchievementEvaluationRateLimiter;
use App\Domain\Flashcards\Support\DeckRateLimiter;
use App\Domain\Media\Support\CardMediaRateLimiter;
use App\Domain\Media\Support\MediaAssetRateLimiter;
use App\Domain\Media\Support\ToolAudioSignedUrlRateLimiter;
use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class FlashcardMediaRateLimiterRegistrar
{
    public static function register(): void
    {
        self::registerDeckRateLimiters();
        self::registerCardAndMediaRateLimiters();
        self::registerToolAndCompatibilityRateLimiters();
    }

    private static function registerDeckRateLimiters(): void
    {
        // Very large offline deck-create backlogs can still throttle before idempotent de-dupe.
        $deckCreateRateLimiter = DeckRateLimiter::forCreate();
        RateLimiter::for(DeckRateLimiter::CREATE_NAME, function (Request $request) use ($deckCreateRateLimiter): Limit {
            return $deckCreateRateLimiter->limit($request);
        });

        $deckUpdateRateLimiter = DeckRateLimiter::forUpdate();
        RateLimiter::for(DeckRateLimiter::UPDATE_NAME, function (Request $request) use ($deckUpdateRateLimiter): Limit {
            return $deckUpdateRateLimiter->limit($request);
        });

        $deckDeleteRateLimiter = DeckRateLimiter::forDelete();
        RateLimiter::for(DeckRateLimiter::DELETE_NAME, function (Request $request) use ($deckDeleteRateLimiter): Limit {
            return $deckDeleteRateLimiter->limit($request);
        });
    }

    private static function registerCardAndMediaRateLimiters(): void
    {
        $cardMediaAttachRateLimiter = CardMediaRateLimiter::forAttach();
        RateLimiter::for(CardMediaRateLimiter::ATTACH_NAME, function (Request $request) use ($cardMediaAttachRateLimiter): Limit {
            return $cardMediaAttachRateLimiter->limit($request);
        });

        $cardMediaDetachRateLimiter = CardMediaRateLimiter::forDetach();
        RateLimiter::for(CardMediaRateLimiter::DETACH_NAME, function (Request $request) use ($cardMediaDetachRateLimiter): Limit {
            return $cardMediaDetachRateLimiter->limit($request);
        });

        $mediaAssetCreateRateLimiter = MediaAssetRateLimiter::forCreate();
        RateLimiter::for(MediaAssetRateLimiter::CREATE_NAME, function (Request $request) use ($mediaAssetCreateRateLimiter): Limit {
            return $mediaAssetCreateRateLimiter->limit($request);
        });

        $mediaAssetDeleteRateLimiter = MediaAssetRateLimiter::forDelete();
        RateLimiter::for(MediaAssetRateLimiter::DELETE_NAME, function (Request $request) use ($mediaAssetDeleteRateLimiter): Limit {
            return $mediaAssetDeleteRateLimiter->limit($request);
        });
    }

    private static function registerToolAndCompatibilityRateLimiters(): void
    {
        RateLimiter::for(
            ToolAudioSignedUrlRateLimiter::NAME,
            fn (Request $request): Limit => resolve(ToolAudioSignedUrlRateLimiter::class)->limit($request),
        );

        RateLimiter::for(
            AchievementEvaluationRateLimiter::NAME,
            fn (Request $request): Limit => resolve(AchievementEvaluationRateLimiter::class)->limit($request),
        );

        RateLimiter::for(
            StudyCompatibilityTrafficRateLimiter::NETWORK_NAME,
            fn (Request $request): Limit => StudyCompatibilityTrafficRateLimiter::networkLimit($request),
        );
        RateLimiter::for(
            StudyCompatibilityTrafficRateLimiter::READ_NAME,
            fn (Request $request): Limit => StudyCompatibilityTrafficRateLimiter::readLimit($request),
        );
        RateLimiter::for(
            StudyCompatibilityTrafficRateLimiter::MEDIA_NAME,
            fn (Request $request): Limit => StudyCompatibilityTrafficRateLimiter::mediaLimit($request),
        );
    }
}
