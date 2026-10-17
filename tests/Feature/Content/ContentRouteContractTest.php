<?php

namespace Tests\Feature\Content;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ContentRouteContractTest extends TestCase
{
    private const UUID_PATTERN = '[\\da-fA-F]{8}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{12}';

    public function test_api_content_routes_preserve_registration_order_actions_middleware_and_constraints(): void
    {
        $actualRoutes = $this->actualContentRoutes();

        $this->assertSame(array_merge(
            $this->expectedEpisodeRoutes(),
            $this->expectedGenerationRoutes(),
            $this->expectedScriptRoutes(),
            $this->expectedCourseRoutes(),
        ), $actualRoutes);
    }

    /** @return list<array<string, mixed>> */
    private function actualContentRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => str_starts_with(
                $route->getActionName(),
                'App\\Http\\Controllers\\Api\\Content\\',
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
    }

    /** @return list<array<string, mixed>> */
    private function expectedEpisodeRoutes(): array
    {
        $uuid = self::UUID_PATTERN;

        return [
            $this->expectedRoute('GET|HEAD', 'api/convolab/episodes', 'ListContentEpisodesController'),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/episodes/{episodeId}',
                'ShowContentEpisodeController',
                options: ['wheres' => ['episodeId' => $uuid]],
            ),
            $this->expectedRoute(
                'PATCH',
                'api/convolab/episodes/{episodeId}',
                'UpdateContentEpisodeController',
                ['throttle' => 'content-episode-update', 'wheres' => ['episodeId' => $uuid]],
            ),
            $this->expectedRoute(
                'DELETE',
                'api/convolab/episodes/{episodeId}',
                'DeleteContentEpisodeController',
                ['throttle' => 'content-episode-delete', 'wheres' => ['episodeId' => $uuid]],
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function expectedGenerationRoutes(): array
    {
        $uuid = self::UUID_PATTERN;

        return [
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/episodes/{episodeId}/audio/{track}',
                'DownloadContentEpisodeAudioController',
                options: ['wheres' => [
                    'episodeId' => $uuid,
                    'track' => 'default|0.7|0.85|1.0',
                ]],
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function expectedScriptRoutes(): array
    {
        $uuid = self::UUID_PATTERN;

        return [
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/scripts/media/{mediaId}',
                'DownloadContentAudioScriptMediaController',
                ['throttle' => 'content-audio-script-media-read', 'wheres' => ['mediaId' => $uuid]],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/scripts/{episodeId}/status',
                'ShowContentAudioScriptController',
                options: ['wheres' => ['episodeId' => $uuid]],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/scripts/{episodeId}/audio/{renderId}',
                'DownloadContentAudioScriptRenderController',
                ['throttle' => 'content-audio-script-media-read', 'wheres' => ['episodeId' => $uuid, 'renderId' => $uuid]],
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function expectedCourseRoutes(): array
    {
        $uuid = self::UUID_PATTERN;

        return [
            $this->expectedRoute('GET|HEAD', 'api/convolab/courses', 'ListContentCoursesController'),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/courses/{courseId}',
                'ShowContentCourseController',
                options: ['wheres' => ['courseId' => $uuid]],
            ),
            $this->expectedRoute(
                'PATCH',
                'api/convolab/courses/{courseId}',
                'UpdateContentCourseController',
                ['throttle' => 'content-course-update', 'wheres' => ['courseId' => $uuid]],
            ),
            $this->expectedRoute(
                'DELETE',
                'api/convolab/courses/{courseId}',
                'DeleteContentCourseController',
                ['throttle' => 'content-course-delete', 'wheres' => ['courseId' => $uuid]],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/courses/{courseId}/audio',
                'DownloadContentCourseAudioController',
                options: ['wheres' => ['courseId' => $uuid]],
            ),
        ];
    }

    public function test_content_routes_remain_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'PUT api/convolab/admin/pronunciation-dictionaries',
            'GET|HEAD api/convolab/episodes',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'GET|HEAD api/convolab/courses/{courseId}/audio',
            'GET|HEAD api/feature-flags',
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
        array $options = [],
    ): array {
        $middleware = ['api', 'auth:sanctum'];
        $throttle = $options['throttle'] ?? null;
        $wheres = $options['wheres'] ?? [];

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
