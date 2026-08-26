<?php

use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use Illuminate\Support\Facades\Route;

/** @var callable(): void $publicMediaAnalyticsRoutes */
$publicMediaAnalyticsRoutes = require __DIR__.'/api/public-media-analytics.php';
$publicMediaAnalyticsRoutes();

/**
 * @var array{
 *     publicAndBrowser: callable(): void,
 *     authenticatedConvoLab: callable(): void,
 *     authenticatedAccountAndTokens: callable(): void,
 * } $authRoutes
 */
$authRoutes = require __DIR__.'/api/auth.php';
$authRoutes['publicAndBrowser']();

/** @var callable(): void $deckRoutes */
$deckRoutes = require __DIR__.'/api/decks.php';

/** @var list<callable(): void> $authenticatedRouteRegistrars */
$authenticatedRouteRegistrars = [
    $authRoutes['authenticatedConvoLab'],
    require __DIR__.'/api/admin.php',
    require __DIR__.'/api/content.php',
    require __DIR__.'/api/feature-flags.php',
    $authRoutes['authenticatedAccountAndTokens'],
    require __DIR__.'/api/courses.php',
    require __DIR__.'/api/reviews.php',
    require __DIR__.'/api/cards.php',
    require __DIR__.'/api/media-assets.php',
    require __DIR__.'/api/sync-feed.php',
];

/** @var list<callable(): void> $studyRouteRegistrars */
$studyRouteRegistrars = [
    require __DIR__.'/api/study-session-bootstrap.php',
    require __DIR__.'/api/study-daily-audio.php',
    require __DIR__.'/api/study-exports.php',
    require __DIR__.'/api/study-imports.php',
    require __DIR__.'/api/study-browser.php',
    require __DIR__.'/api/study-card-authoring.php',
    require __DIR__.'/api/study-card-library.php',
    require __DIR__.'/api/study-activity.php',
    require __DIR__.'/api/study-reviews.php',
    require __DIR__.'/api/study-card-mutations.php',
    require __DIR__.'/api/study-media.php',
    require __DIR__.'/api/study-preferences-japanese.php',
    require __DIR__.'/api/study-calendar.php',
];

Route::middleware('auth:sanctum')->group(function () use ($authenticatedRouteRegistrars, $deckRoutes, $studyRouteRegistrars): void {
    foreach ($authenticatedRouteRegistrars as $registerRoutes) {
        $registerRoutes();
    }

    // Preserve the retired ConvoLab proxy ceilings: every request consumes the shared
    // network bucket, while reads consume an additional actor-scoped read or media bucket.
    Route::middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::NETWORK_NAME)
        ->group(function () use ($studyRouteRegistrars): void {
            foreach ($studyRouteRegistrars as $registerRoutes) {
                $registerRoutes();
            }
        });

    $deckRoutes();
});

/** @var callable(): void $achievementRoutes */
$achievementRoutes = require __DIR__.'/api/achievements.php';
$achievementRoutes();

unset($achievementRoutes, $authenticatedRouteRegistrars, $authRoutes, $deckRoutes, $publicMediaAnalyticsRoutes, $studyRouteRegistrars);
