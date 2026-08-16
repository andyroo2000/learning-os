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
            ->filter(static fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'api/study/google-calendar')
                && in_array('api', $route->gatherMiddleware(), strict: true))
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
                'methods' => 'GET|HEAD',
                'action' => 'ListReadableGoogleCalendarsController',
                'middleware' => ['api', 'auth:sanctum', 'throttle:study-compatibility-network', 'throttle:study-compatibility-read', 'throttle:google-calendar-provider-read'],
            ],
            [
                'methods' => 'POST',
                'action' => 'PreviewGoogleCalendarController',
                'middleware' => ['api', 'auth:sanctum', 'throttle:study-compatibility-network', 'throttle:study-compatibility-read', 'throttle:google-calendar-provider-read'],
            ],
            [
                'methods' => 'POST',
                'action' => 'SyncGoogleCalendarController',
                'middleware' => ['api', 'auth:sanctum', 'throttle:study-compatibility-network', 'throttle:google-calendar-connection-write'],
            ],
            [
                'methods' => 'PUT',
                'action' => 'UpdateGoogleCalendarSettingsController',
                'middleware' => ['api', 'auth:sanctum', 'throttle:study-compatibility-network', 'throttle:google-calendar-connection-write'],
            ],
            [
                'methods' => 'POST',
                'action' => 'CreateGoogleCalendarConnectIntentController',
                'middleware' => ['api', 'auth:sanctum', 'throttle:study-compatibility-network', 'throttle:google-calendar-connection-write'],
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
