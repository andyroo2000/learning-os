<?php

namespace Tests\Feature\Reviews;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReviewRouteContractTest extends TestCase
{
    public function test_api_review_routes_preserve_registration_order_actions_middleware_and_constraints(): void
    {
        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => $route->uri() === 'api/card-review-events'
                || str_starts_with($route->uri(), 'api/card-review-events/'))
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

        $ulid = '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}';

        $this->assertSame([
            $this->expectedRoute('GET|HEAD', 'api/card-review-events', 'ListReviewEventsController'),
            $this->expectedRoute(
                'POST',
                'api/card-review-events/batch',
                'StoreCardReviewEventBatchController',
                'card-review-event-create',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/card-review-events/{cardReviewEvent}',
                'ShowCardReviewEventController',
                wheres: ['cardReviewEvent' => $ulid],
            ),
            $this->expectedRoute(
                'DELETE',
                'api/card-review-events/{cardReviewEvent}',
                'UndoCardReviewEventController',
                'card-review-event-undo',
                ['cardReviewEvent' => $ulid],
            ),
            $this->expectedRoute(
                'POST',
                'api/card-review-events',
                'StoreCardReviewEventController',
                'card-review-event-create',
            ),
        ], $actualRoutes);
    }

    public function test_review_routes_remain_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/courses/{course}',
            'GET|HEAD api/card-review-events',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'POST api/card-review-events',
            'GET|HEAD api/cards/due',
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
        ?string $throttle = null,
        array $wheres = [],
    ): array {
        $middleware = ['api', 'auth:sanctum'];

        if ($throttle !== null) {
            $middleware[] = 'throttle:'.$throttle;
        }

        return [
            'methods' => $methods,
            'uri' => $uri,
            'name' => null,
            'action' => $action,
            'middleware' => $middleware,
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
