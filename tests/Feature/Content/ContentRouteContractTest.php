<?php

namespace Tests\Feature\Content;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ContentRouteContractTest extends TestCase
{
    public function test_api_content_routes_preserve_registration_order_actions_middleware_and_constraints(): void
    {
        $actualRoutes = collect(Route::getRoutes()->getRoutes())
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

        $uuid = '[\\da-fA-F]{8}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{12}';

        $this->assertSame([
            $this->expectedRoute('GET|HEAD', 'api/convolab/episodes', 'ListContentEpisodesController'),
            $this->expectedRoute(
                'POST',
                'api/convolab/episodes',
                'StoreContentEpisodeController',
                'content-episode-create',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/episodes/{episodeId}',
                'ShowContentEpisodeController',
                wheres: ['episodeId' => $uuid],
            ),
            $this->expectedRoute(
                'PATCH',
                'api/convolab/episodes/{episodeId}',
                'UpdateContentEpisodeController',
                'content-episode-update',
                ['episodeId' => $uuid],
            ),
            $this->expectedRoute(
                'DELETE',
                'api/convolab/episodes/{episodeId}',
                'DeleteContentEpisodeController',
                'content-episode-delete',
                ['episodeId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/dialogue/generate',
                'GenerateContentDialogueController',
                'content-dialogue-generation',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/dialogue/job/{jobId}',
                'ShowContentDialogueGenerationJobController',
                wheres: ['jobId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/images/generate',
                'GenerateContentImagesController',
                'content-image-generation',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/images/job/{jobId}',
                'ShowContentImageGenerationJobController',
                wheres: ['jobId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/audio/generate',
                'GenerateContentAudioController',
                'content-audio-generation',
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/audio/generate-all-speeds',
                'GenerateAllSpeedsContentAudioController',
                'content-audio-generation',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/audio/job/{jobId}',
                'ShowContentAudioGenerationJobController',
                wheres: ['jobId' => $uuid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/episodes/{episodeId}/audio/{track}',
                'DownloadContentEpisodeAudioController',
                wheres: [
                    'episodeId' => $uuid,
                    'track' => 'default|0.7|0.85|1.0',
                ],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/scripts/media/{mediaId}',
                'DownloadContentAudioScriptMediaController',
                'content-audio-script-media-read',
                ['mediaId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/scripts',
                'StoreContentAudioScriptController',
                'content-audio-script-generation',
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/scripts/{episodeId}/annotate',
                'AnnotateContentAudioScriptController',
                'content-audio-script-generation',
                ['episodeId' => $uuid],
            ),
            $this->expectedRoute(
                'PATCH',
                'api/convolab/scripts/{episodeId}/segments',
                'UpdateContentAudioScriptSegmentsController',
                'content-audio-script-update',
                ['episodeId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/scripts/{episodeId}/render',
                'GenerateContentAudioScriptRenderController',
                'content-audio-script-generation',
                ['episodeId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/scripts/{episodeId}/images',
                'GenerateContentAudioScriptImagesController',
                'content-audio-script-generation',
                ['episodeId' => $uuid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/scripts/{episodeId}/status',
                'ShowContentAudioScriptController',
                wheres: ['episodeId' => $uuid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/scripts/job/{jobId}',
                'ShowContentAudioScriptGenerationJobController',
                wheres: ['jobId' => $uuid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/scripts/{episodeId}/audio/{renderId}',
                'DownloadContentAudioScriptRenderController',
                'content-audio-script-media-read',
                ['episodeId' => $uuid, 'renderId' => $uuid],
            ),
            $this->expectedRoute('GET|HEAD', 'api/convolab/courses', 'ListContentCoursesController'),
            $this->expectedRoute(
                'POST',
                'api/convolab/courses',
                'StoreContentCourseController',
                'content-course-create',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/courses/{courseId}',
                'ShowContentCourseController',
                wheres: ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'PATCH',
                'api/convolab/courses/{courseId}',
                'UpdateContentCourseController',
                'content-course-update',
                ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'DELETE',
                'api/convolab/courses/{courseId}',
                'DeleteContentCourseController',
                'content-course-delete',
                ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/courses/{courseId}/generate',
                'GenerateContentCourseController',
                'content-course-generation',
                ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/courses/{courseId}/status',
                'ShowContentCourseGenerationStatusController',
                wheres: ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/courses/{courseId}/reset',
                'ResetContentCourseGenerationController',
                'content-course-generation-reset',
                ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/courses/{courseId}/retry',
                'RetryContentCourseGenerationController',
                'content-course-generation',
                ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/courses/{courseId}/audio',
                'DownloadContentCourseAudioController',
                wheres: ['courseId' => $uuid],
            ),
        ], $actualRoutes);
    }

    public function test_content_routes_remain_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/convolab/admin/courses/{courseId}/line-renderings/{renderingId}',
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
