<?php

namespace Tests\Feature\Admin;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminRouteContractTest extends TestCase
{
    private const UUID_PATTERN = '[\\da-fA-F]{8}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{12}';

    public function test_api_admin_routes_preserve_registration_order_actions_middleware_and_constraints(): void
    {
        $actualRoutes = $this->actualAdminRoutes();

        $this->assertSame(array_merge(
            $this->expectedUserAndInviteRoutes(),
            $this->expectedAvatarAndPronunciationRoutes(),
        ), $actualRoutes);
    }

    /** @return list<array<string, mixed>> */
    private function actualAdminRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
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
    }

    /** @return list<array<string, mixed>> */
    private function expectedUserAndInviteRoutes(): array
    {
        $uuid = self::UUID_PATTERN;

        return [
            $this->expectedRoute('GET|HEAD', 'api/convolab/admin/stats', 'ShowAdminStatsController'),
            $this->expectedRoute('GET|HEAD', 'api/convolab/admin/users', 'ListAdminUsersController'),
            $this->expectedRoute(
                'DELETE',
                'api/convolab/admin/users/{convoLabUserId}',
                'DeleteAdminUserController',
                ['throttle' => 'convolab-admin-user-delete', 'wheres' => ['convoLabUserId' => $uuid]],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/admin/users/{convoLabUserId}/info',
                'ShowAdminUserController',
                options: ['wheres' => ['convoLabUserId' => $uuid]],
            ),
            $this->expectedRoute('GET|HEAD', 'api/convolab/admin/invite-codes', 'ListAdminInviteCodesController'),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/invite-codes',
                'CreateAdminInviteCodeController',
                ['throttle' => 'convolab-admin-invite-create'],
            ),
            $this->expectedRoute(
                'DELETE',
                'api/convolab/admin/invite-codes/{inviteId}',
                'DeleteAdminInviteCodeController',
                ['throttle' => 'convolab-admin-invite-delete', 'wheres' => ['inviteId' => $uuid]],
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function expectedAvatarAndPronunciationRoutes(): array
    {
        $uuid = self::UUID_PATTERN;

        return [
            $this->expectedRoute(
                'GET|HEAD',
                'api/convolab/admin/avatars/speaker/{filename}/original',
                'ShowAdminSpeakerAvatarOriginalController',
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/avatars/speaker/{filename}/upload',
                'UploadAdminSpeakerAvatarController',
                ['throttle' => 'convolab-admin-speaker-avatar-upload'],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/avatars/speaker/{filename}/recrop',
                'RecropAdminSpeakerAvatarController',
                ['throttle' => 'convolab-admin-speaker-avatar-recrop'],
            ),
            $this->expectedRoute(
                'POST',
                'api/convolab/admin/avatars/user/{convoLabUserId}/upload',
                'UploadAdminUserAvatarController',
                ['throttle' => 'convolab-admin-user-avatar-upload', 'wheres' => ['convoLabUserId' => $uuid]],
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
                ['throttle' => 'convolab-admin-pronunciation-dictionary-update'],
            ),
        ];
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
            'PUT api/convolab/admin/pronunciation-dictionaries',
            'GET|HEAD api/convolab/episodes',
        );
    }

    /**
     * @param  array{throttle?: string, wheres?: array<string, string>}  $options
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

        if ($throttle !== null) {
            $middleware[] = 'throttle:'.$throttle;
        }

        return [
            'methods' => $methods,
            'uri' => $uri,
            'name' => null,
            'action' => $action,
            'middleware' => $middleware,
            'wheres' => $options['wheres'] ?? [],
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
