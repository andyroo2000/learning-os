<?php

namespace Tests\Feature\Admin;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminRouteContractTest extends TestCase
{
    public function test_api_admin_routes_preserve_registration_order_actions_middleware_and_constraints(): void
    {
        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => str_starts_with(
                $route->getActionName(),
                'App\\Http\\Controllers\\Api\\Admin\\',
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
            $this->expectedRoute('GET|HEAD', 'api/convolab/admin/stats', 'ShowAdminStatsController'),
            $this->expectedRoute('GET|HEAD', 'api/convolab/admin/users', 'ListAdminUsersController'),
            $this->expectedRoute(
                'DELETE',
                'api/convolab/admin/users/{convoLabUserId}',
                'DeleteAdminUserController',
                'convolab-admin-user-delete',
                ['convoLabUserId' => $uuid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/admin/users/{convoLabUserId}/info',
                'ShowAdminUserController',
                wheres: ['convoLabUserId' => $uuid],
            ),
            $this->expectedRoute('GET|HEAD', 'api/convolab/admin/invite-codes', 'ListAdminInviteCodesController'),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/invite-codes',
                'CreateAdminInviteCodeController',
                'convolab-admin-invite-create',
            ),
            $this->expectedRoute(
                'DELETE',
                'api/convolab/admin/invite-codes/{inviteId}',
                'DeleteAdminInviteCodeController',
                'convolab-admin-invite-delete',
                ['inviteId' => $uuid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/admin/avatars/speaker/{filename}/original',
                'ShowAdminSpeakerAvatarOriginalController',
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/avatars/speaker/{filename}/upload',
                'UploadAdminSpeakerAvatarController',
                'convolab-admin-speaker-avatar-upload',
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/avatars/speaker/{filename}/recrop',
                'RecropAdminSpeakerAvatarController',
                'convolab-admin-speaker-avatar-recrop',
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/avatars/user/{convoLabUserId}/upload',
                'UploadAdminUserAvatarController',
                'convolab-admin-user-avatar-upload',
                ['convoLabUserId' => $uuid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/admin/avatars/speakers',
                'ListAdminSpeakerAvatarsController',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/admin/pronunciation-dictionaries',
                'ShowAdminPronunciationDictionaryController',
            ),
            $this->expectedRoute(
                'PUT',
                'api/convolab/admin/pronunciation-dictionaries',
                'UpdateAdminPronunciationDictionaryController',
                'convolab-admin-pronunciation-dictionary-update',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/admin/script-lab/courses',
                'ListAdminScriptLabCoursesController',
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/script-lab/courses',
                'StoreAdminScriptLabCourseController',
                'convolab-admin-script-lab-course-create',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/admin/script-lab/courses/{courseId}',
                'ShowAdminScriptLabCourseController',
                wheres: ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'DELETE',
                'api/convolab/admin/script-lab/courses',
                'DeleteAdminScriptLabCoursesController',
                'convolab-admin-script-lab-course-delete',
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/script-lab/sentence-script',
                'GenerateAdminSentenceScriptController',
                'convolab-admin-sentence-script-generate',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/admin/script-lab/sentence-tests',
                'ListAdminSentenceScriptTestsController',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/admin/script-lab/sentence-tests/{testId}',
                'ShowAdminSentenceScriptTestController',
                wheres: ['testId' => $uuid],
            ),
            $this->expectedRoute(
                'DELETE',
                'api/convolab/admin/script-lab/sentence-tests',
                'DeleteAdminSentenceScriptTestsController',
                'convolab-admin-sentence-script-delete',
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/script-lab/synthesize-line',
                'SynthesizeAdminScriptLabLineController',
                'convolab-admin-script-lab-line-synthesize',
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/script-lab/test-pronunciation',
                'TestAdminPronunciationController',
                'convolab-admin-script-lab-pronunciation-test',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/admin/script-lab/audio/{renderingId}',
                'DownloadAdminScriptLabAudioController',
                wheres: ['renderingId' => $uuid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/admin/courses/{courseId}/pipeline-data',
                'ShowAdminCoursePipelineController',
                wheres: ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'PUT',
                'api/convolab/admin/courses/{courseId}/pipeline-data',
                'UpdateAdminCoursePipelineController',
                'convolab-admin-course-pipeline-update',
                ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/courses/{courseId}/build-script-config',
                'BuildAdminCourseScriptConfigController',
                wheres: ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/courses/{courseId}/build-prompt',
                'BuildAdminCoursePromptController',
                wheres: ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/courses/{courseId}/generate-dialogue',
                'GenerateAdminCourseDialogueController',
                'convolab-admin-course-dialogue-generate',
                ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/courses/{courseId}/generate-script',
                'GenerateAdminCourseScriptController',
                'convolab-admin-course-script-generate',
                ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/courses/{courseId}/generate-audio',
                'GenerateAdminCourseAudioController',
                'convolab-admin-course-audio-generate',
                ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/courses/{courseId}/synthesize-line',
                'SynthesizeAdminCourseLineController',
                'convolab-admin-course-line-synthesize',
                ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/admin/courses/{courseId}/line-renderings',
                'ListAdminCourseLineRenderingsController',
                wheres: ['courseId' => $uuid],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/admin/courses/{courseId}/line-renderings/{renderingId}/audio',
                'DownloadAdminCourseLineRenderingController',
                wheres: ['courseId' => $uuid, 'renderingId' => $uuid],
            ),
            $this->expectedRoute(
                'DELETE',
                'api/convolab/admin/courses/{courseId}/line-renderings/{renderingId}',
                'DeleteAdminCourseLineRenderingController',
                'convolab-admin-course-line-delete',
                ['courseId' => $uuid, 'renderingId' => $uuid],
            ),
        ], $actualRoutes);
    }

    public function test_admin_routes_remain_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'POST api/convolab/auth/verification/send',
            'GET|HEAD api/convolab/admin/stats',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/convolab/admin/courses/{courseId}/line-renderings/{renderingId}',
            'GET|HEAD api/convolab/episodes',
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
