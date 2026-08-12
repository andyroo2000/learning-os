<?php

namespace Tests\Feature\Study;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StudyReviewRouteContractTest extends TestCase
{
    private const REVIEW_LOG_ID_PATTERN = '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}';

    public function test_study_review_routes_preserve_registration_order_names_actions_middleware_and_constraints(): void
    {
        $actions = [
            'StoreStudyReviewController',
            'StoreStudyReviewUndoController',
            'UndoStudyReviewController',
        ];

        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => in_array(
                class_basename($route->getActionName()),
                $actions,
                strict: true,
            ))
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
            $this->expectedRoute(
                'POST',
                'api/study/reviews',
                'StoreStudyReviewController',
                'throttle:card-review-event-create',
            ),
            $this->expectedRoute(
                'POST',
                'api/study/reviews/undo',
                'StoreStudyReviewUndoController',
                'throttle:card-review-event-undo',
            ),
            $this->expectedRoute(
                'DELETE',
                'api/study/reviews/{reviewLogId}',
                'UndoStudyReviewController',
                'throttle:card-review-event-undo',
                ['reviewLogId' => self::REVIEW_LOG_ID_PATTERN],
            ),
        ], $actualRoutes);
    }

    public function test_study_review_routes_remain_inside_the_network_limit_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/study/activity-sessions/{clientSessionId}',
            'POST api/study/reviews',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/study/reviews/{reviewLogId}',
            'POST api/study/cards',
        );
    }

    /**
     * @param  array<string, string>  $wheres
     * @return array{
     *     methods: string,
     *     uri: string,
     *     name: null,
     *     action: string,
     *     middleware: list<string>,
     *     wheres: array<string, string>
     * }
     */
    private function expectedRoute(
        string $methods,
        string $uri,
        string $action,
        string $rateLimitMiddleware,
        array $wheres = [],
    ): array {
        return [
            'methods' => $methods,
            'uri' => $uri,
            'name' => null,
            'action' => $action,
            'middleware' => [
                'api',
                'auth:sanctum',
                'throttle:study-compatibility-network',
                $rateLimitMiddleware,
            ],
            'wheres' => $wheres,
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
