<?php

namespace Tests\Feature\Study;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StudySessionBootstrapRouteContractTest extends TestCase
{
    public function test_study_session_bootstrap_routes_preserve_registration_order_names_actions_and_middleware(): void
    {
        $uris = [
            'api/study/session/start',
            'api/study/lessons/start',
            'api/study/offline-reserve',
        ];

        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => in_array($route->uri(), $uris, strict: true))
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
            $this->expectedRoute('api/study/session/start', 'StartStudySessionController'),
            $this->expectedRoute('api/study/lessons/start', 'StartStudyLessonController'),
            $this->expectedRoute('api/study/offline-reserve', 'BuildStudyOfflineReserveController'),
        ], $actualRoutes);
    }

    public function test_study_session_bootstrap_routes_remain_inside_the_network_limit_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'GET|HEAD api/sync/feed',
            'POST api/study/session/start',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'POST api/study/offline-reserve',
            'POST api/daily-audio-practice',
        );
    }

    /**
     * @return array{
     *     methods: string,
     *     uri: string,
     *     name: null,
     *     action: string,
     *     middleware: list<string>,
     *     wheres: array{}
     * }
     */
    private function expectedRoute(string $uri, string $action): array
    {
        return [
            'methods' => 'POST',
            'uri' => $uri,
            'name' => null,
            'action' => $action,
            'middleware' => [
                'api',
                'auth:sanctum',
                'throttle:study-compatibility-network',
                'throttle:study-session-start',
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
