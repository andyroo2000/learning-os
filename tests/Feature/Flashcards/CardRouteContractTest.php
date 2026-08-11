<?php

namespace Tests\Feature\Flashcards;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CardRouteContractTest extends TestCase
{
    public function test_api_card_routes_preserve_registration_order_actions_middleware_and_constraints(): void
    {
        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => $route->uri() === 'api/cards'
                || str_starts_with($route->uri(), 'api/cards/'))
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
            $this->expectedRoute('GET|HEAD', 'api/cards/due', 'ListDueCardsController'),
            $this->expectedRoute('GET|HEAD', 'api/cards/new', 'ListNewCardsController'),
            $this->expectedRoute(
                'POST',
                'api/cards/new/reorder',
                'ReorderNewCardQueueController',
                'new-card-queue-reorder',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/cards/{card}',
                'ShowCardController',
                wheres: ['card' => $ulid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/cards/{card}/review-events',
                'ListCardReviewEventsController',
                wheres: ['card' => $ulid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/cards/{card}/media-assets',
                'ListCardMediaAssetsController',
                wheres: ['card' => $ulid],
            ),
            $this->expectedRoute(
                'POST',
                'api/cards/{card}/media-assets',
                'AttachMediaToCardController',
                'card-media-attach',
                ['card' => $ulid],
            ),
            $this->expectedRoute(
                'DELETE',
                'api/cards/{card}/media-assets/{mediaAsset}',
                'DetachMediaFromCardController',
                'card-media-detach',
                ['card' => $ulid, 'mediaAsset' => $ulid],
            ),
            $this->expectedRoute('GET|HEAD', 'api/cards', 'ListCardsController'),
            $this->expectedRoute(
                'POST',
                'api/cards',
                'StoreCardController',
                'study-card-create',
            ),
            $this->expectedRoute(
                'POST',
                'api/cards/{card}/actions',
                'PerformCardStudyActionController',
                'study-card-action',
                ['card' => $ulid],
            ),
            $this->expectedRoute(
                'PATCH',
                'api/cards/{card}/study-status',
                'UpdateCardStudyStatusController',
                'study-card-update',
                ['card' => $ulid],
            ),
            $this->expectedRoute(
                'PUT',
                'api/cards/{card}',
                'UpdateCardController',
                'study-card-update',
                ['card' => $ulid],
            ),
            $this->expectedRoute(
                'DELETE',
                'api/cards/{card}',
                'DeleteCardController',
                'study-card-delete',
                ['card' => $ulid],
            ),
        ], $actualRoutes);
    }

    public function test_card_routes_remain_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'POST api/card-review-events',
            'GET|HEAD api/cards/due',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/cards/{card}',
            'GET|HEAD api/media-assets',
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
