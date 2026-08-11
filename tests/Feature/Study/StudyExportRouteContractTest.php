<?php

namespace Tests\Feature\Study;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StudyExportRouteContractTest extends TestCase
{
    public function test_study_export_routes_preserve_registration_order_names_actions_middleware_and_constraints(): void
    {
        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => $route->uri() === 'api/study/export'
                || str_starts_with($route->uri(), 'api/study/export/'))
            ->map(static fn (LaravelRoute $route): array => [
                'methods' => implode('|', $route->methods()),
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'action' => class_basename($route->getActionName()),
                'middleware' => $route->gatherMiddleware(),
                'wheres' => $route->wheres,
            ])
            ->values()
            ->all();

        $this->assertSame([
            $this->expectedRoute('api/study/export', 'ShowStudyExportManifestController'),
            $this->expectedRoute(
                'api/study/export/card-drafts',
                'ListStudyExportCardDraftsController',
                'api.study.export.card-drafts',
            ),
            $this->expectedRoute(
                'api/study/export/card-media',
                'ListStudyExportCardMediaController',
                'api.study.export.card-media',
            ),
            $this->expectedRoute(
                'api/study/export/cards',
                'ListStudyExportCardsController',
                'api.study.export.cards',
            ),
            $this->expectedRoute(
                'api/study/export/courses',
                'ListStudyExportCoursesController',
                'api.study.export.courses',
            ),
            $this->expectedRoute(
                'api/study/export/decks',
                'ListStudyExportDecksController',
                'api.study.export.decks',
            ),
            $this->expectedRoute(
                'api/study/export/imports',
                'ListStudyExportImportJobsController',
                'api.study.export.imports',
            ),
            $this->expectedRoute(
                'api/study/export/media',
                'ListStudyExportMediaAssetsController',
                'api.study.export.media',
            ),
            $this->expectedRoute(
                'api/study/export/media-assets',
                'ListStudyExportMediaAssetsController',
                'api.study.export.media-assets',
            ),
            $this->expectedRoute(
                'api/study/export/review-logs',
                'ListStudyExportReviewEventsController',
                'api.study.export.review-logs',
            ),
            $this->expectedRoute(
                'api/study/export/review-events',
                'ListStudyExportReviewEventsController',
                'api.study.export.review-events',
            ),
            $this->expectedRoute(
                'api/study/export/settings',
                'ShowStudyExportSettingsController',
                'api.study.export.settings',
            ),
        ], $actualRoutes);
    }

    public function test_study_export_routes_remain_inside_the_network_limit_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'GET|HEAD api/daily-audio-practice/{practiceId}/tracks/{trackId}/audio',
            'GET|HEAD api/study/export',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'GET|HEAD api/study/export/settings',
            'GET|HEAD api/study/imports',
        );
    }

    /**
     * @return array{
     *     methods: string,
     *     uri: string,
     *     name: ?string,
     *     action: string,
     *     middleware: list<string>,
     *     wheres: array<string, string>
     * }
     */
    private function expectedRoute(string $uri, string $action, ?string $name = null): array
    {
        return [
            'methods' => 'GET|HEAD',
            'uri' => $uri,
            'name' => $name,
            'action' => $action,
            'middleware' => [
                'api',
                'auth:sanctum',
                'throttle:study-compatibility-network',
                'throttle:study-compatibility-read',
            ],
            'wheres' => [],
        ];
    }

    /** @param Collection<int, string> $routeOrder */
    private function assertImmediatelyBefore(Collection $routeOrder, string $before, string $after): void
    {
        $beforeIndex = $routeOrder->search($before, strict: true);
        $afterIndex = $routeOrder->search($after, strict: true);

        $this->assertIsInt($beforeIndex, "Route [$before] is not registered.");
        $this->assertIsInt($afterIndex, "Route [$after] is not registered.");
        $this->assertSame($beforeIndex + 1, $afterIndex, "Route [$before] must remain immediately before [$after].");
    }
}
