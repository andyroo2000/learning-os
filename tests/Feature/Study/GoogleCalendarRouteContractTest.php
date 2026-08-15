<?php

namespace Tests\Feature\Study;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GoogleCalendarRouteContractTest extends TestCase
{
    public function test_calendar_routes_are_authenticated_and_rate_limited(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => $route->uri() === 'api/study/google-calendar')
            ->map(static fn (LaravelRoute $route): array => [
                'methods' => implode('|', $route->methods()),
                'action' => class_basename($route->getActionName()),
                'middleware' => $route->gatherMiddleware(),
            ])
            ->values()
            ->all();

        $this->assertSame([
            [
                'methods' => 'GET|HEAD',
                'action' => 'ShowGoogleCalendarConnectionController',
                'middleware' => ['api', 'auth:sanctum', 'throttle:study-compatibility-network', 'throttle:study-compatibility-read'],
            ],
            [
                'methods' => 'DELETE',
                'action' => 'DisconnectGoogleCalendarController',
                'middleware' => ['api', 'auth:sanctum', 'throttle:study-compatibility-network', 'throttle:google-calendar-connection-write'],
            ],
        ], $routes);

        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();
        $calendarEnd = $routeOrder->search('DELETE api/study/google-calendar', strict: true);
        $deckStart = $routeOrder->search('GET|HEAD api/decks/{deck}', strict: true);

        $this->assertIsInt($calendarEnd);
        $this->assertSame($calendarEnd + 1, $deckStart);
    }
}
