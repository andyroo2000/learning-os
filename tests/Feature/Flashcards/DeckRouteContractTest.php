<?php

namespace Tests\Feature\Flashcards;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DeckRouteContractTest extends TestCase
{
    public function test_api_deck_routes_preserve_registration_order_actions_middleware_and_constraints(): void
    {
        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => $route->uri() === 'api/decks'
                || str_starts_with($route->uri(), 'api/decks/'))
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
            $this->expectedRoute(
                'GET|HEAD',
                'api/decks/{deck}',
                'ShowDeckController',
                wheres: ['deck' => $ulid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/decks/{deck}/media-assets',
                'ListDeckMediaAssetsController',
                wheres: ['deck' => $ulid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/decks/{deck}/cards',
                'ListDeckCardsController',
                wheres: ['deck' => $ulid],
            ),
            $this->expectedRoute(
                'PUT',
                'api/decks/{deck}',
                'UpdateDeckController',
                'deck-update',
                ['deck' => $ulid],
            ),
            $this->expectedRoute(
                'DELETE',
                'api/decks/{deck}',
                'DeleteDeckController',
                'deck-delete',
                ['deck' => $ulid],
            ),
            $this->expectedRoute('GET|HEAD', 'api/decks', 'ListDecksController'),
            $this->expectedRoute(
                'POST',
                'api/decks',
                'StoreDeckController',
                'deck-create',
            ),
        ], $actualRoutes);
    }

    public function test_deck_routes_remain_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/study/google-calendar',
            'GET|HEAD api/decks/{deck}',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'POST api/decks',
            'GET|HEAD up',
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
