<?php

namespace Tests\Feature\Study;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DailyAudioRouteContractTest extends TestCase
{
    private const UUID_PATTERN = '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}';

    public function test_daily_audio_routes_preserve_registration_order_actions_middleware_and_constraints(): void
    {
        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => $route->uri() === 'api/daily-audio-practice'
                || str_starts_with($route->uri(), 'api/daily-audio-practice/'))
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
                'api/daily-audio-practice',
                'StoreDailyAudioPracticeController',
                'daily-audio-practice-generation',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/daily-audio-practice',
                'ListDailyAudioPracticesController',
                'study-compatibility-read',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/daily-audio-practice/{practiceId}',
                'ShowDailyAudioPracticeController',
                'study-compatibility-read',
                ['practiceId' => self::UUID_PATTERN],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/daily-audio-practice/{practiceId}/status',
                'ShowDailyAudioPracticeStatusController',
                'study-compatibility-read',
                ['practiceId' => self::UUID_PATTERN],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/daily-audio-practice/{practiceId}/tracks/{trackId}/audio',
                'DownloadDailyAudioPracticeTrackController',
                'study-compatibility-media',
                [
                    'practiceId' => self::UUID_PATTERN,
                    'trackId' => self::UUID_PATTERN,
                ],
            ),
        ], $actualRoutes);
    }

    public function test_daily_audio_routes_remain_inside_the_network_limit_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'POST api/study/offline-reserve',
            'POST api/daily-audio-practice',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'GET|HEAD api/daily-audio-practice/{practiceId}/tracks/{trackId}/audio',
            'GET|HEAD api/study/export',
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
        string $throttle,
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
                'throttle:'.$throttle,
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
