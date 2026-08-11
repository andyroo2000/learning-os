<?php

namespace Tests\Feature\Auth;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthRouteContractTest extends TestCase
{
    public function test_api_auth_routes_preserve_registration_order_actions_middleware_and_constraints(): void
    {
        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => str_starts_with(
                $route->getActionName(),
                'App\\Http\\Controllers\\Api\\Auth\\',
            ))
            ->map(static fn (LaravelRoute $route): array => [
                implode('|', $route->methods()),
                $route->uri(),
                $route->getName(),
                class_basename($route->getActionName()),
                $route->gatherMiddleware(),
                $route->wheres,
            ])
            ->values()
            ->all();

        $this->assertSame([
            [
                'POST', 'api/auth/register', null, 'RegisterMobileUserController',
                ['api', 'throttle:mobile-registrations'], [],
            ],
            [
                'POST', 'api/convolab/auth/register', null, 'RegisterConvoLabMobileUserController',
                ['api', 'throttle:convolab-signups'], [],
            ],
            [
                'POST', 'api/auth/password/forgot', null, 'SendPasswordResetLinkController',
                ['api', 'throttle:password-reset-links'], [],
            ],
            [
                'POST', 'api/auth/password/reset', null, 'ResetUserPasswordController',
                ['api', 'throttle:password-reset-tokens'], [],
            ],
            [
                'POST', 'api/auth/tokens', null, 'StoreMobileTokenController',
                ['api', 'throttle:mobile-tokens'], [],
            ],
            [
                'POST', 'api/convolab/browser/auth/login', null, 'AuthenticateConvoLabBrowserUserController',
                ['api', 'throttle:convolab-logins'], [],
            ],
            [
                'POST', 'api/convolab/browser/auth/signup', null, 'RegisterConvoLabBrowserUserController',
                ['api', 'throttle:convolab-signups'], [],
            ],
            [
                'POST', 'api/convolab/browser/auth/verification', null, 'VerifyConvoLabBrowserEmailController',
                ['api', 'throttle:convolab-verification-verify'], [],
            ],
            [
                'POST', 'api/convolab/browser/auth/google/invite', null, 'ClaimConvoLabBrowserGoogleInviteController',
                ['api', 'throttle:convolab-oauth-browser-claim'], [],
            ],
            [
                'GET|HEAD', 'api/convolab/browser/auth/me', null, 'ShowConvoLabBrowserCurrentUserController',
                ['api', 'auth:web'], [],
            ],
            [
                'POST', 'api/convolab/browser/auth/logout', null, 'DestroyConvoLabBrowserSessionController',
                ['api', 'auth:web'], [],
            ],
            [
                'POST', 'api/convolab/browser/auth/verification/send', null,
                'SendConvoLabBrowserVerificationController',
                ['api', 'auth:web', 'throttle:convolab-verification-send'], [],
            ],
            [
                'GET|HEAD', 'api/me', null, 'ShowCurrentUserController',
                ['api', 'auth:sanctum'], [],
            ],
            [
                'DELETE', 'api/convolab/auth/google', null, 'DisconnectConvoLabGoogleIdentityController',
                ['api', 'auth:sanctum', 'throttle:convolab-oauth-disconnect'], [],
            ],
            [
                'GET|HEAD', 'api/convolab/auth/me', null, 'ShowConvoLabCurrentUserController',
                ['api', 'auth:sanctum'], [],
            ],
            [
                'PATCH', 'api/convolab/auth/me', null, 'UpdateConvoLabCurrentUserController',
                ['api', 'auth:sanctum', 'throttle:convolab-profile-update'], [],
            ],
            [
                'PUT', 'api/convolab/auth/me/password', null,
                'UpdateConvoLabCurrentUserPasswordController',
                ['api', 'auth:sanctum', 'throttle:convolab-account-password-update'], [],
            ],
            [
                'DELETE', 'api/convolab/auth/me', null, 'DeleteConvoLabCurrentUserController',
                ['api', 'auth:sanctum', 'throttle:convolab-account-delete'], [],
            ],
            [
                'POST', 'api/convolab/auth/verification/send', null,
                'SendConvoLabVerificationController',
                ['api', 'auth:sanctum', 'throttle:convolab-verification-send'], [],
            ],
            [
                'PUT', 'api/me', null, 'UpdateCurrentUserProfileController',
                ['api', 'auth:sanctum', 'throttle:account-profile-update'], [],
            ],
            [
                'PUT', 'api/me/password', null, 'UpdateCurrentUserPasswordController',
                ['api', 'auth:sanctum', 'throttle:account-password-update'], [],
            ],
            [
                'DELETE', 'api/me', null, 'DeleteCurrentUserController',
                ['api', 'auth:sanctum', 'throttle:account-delete'], [],
            ],
            [
                'GET|HEAD', 'api/auth/tokens', null, 'ListAccessTokensController',
                ['api', 'auth:sanctum'], [],
            ],
            [
                'DELETE', 'api/auth/tokens/current', null, 'DestroyCurrentAccessTokenController',
                ['api', 'auth:sanctum', 'throttle:account-token-revoke'], [],
            ],
            [
                'DELETE', 'api/auth/tokens/{tokenId}', null, 'DestroyAccessTokenController',
                ['api', 'auth:sanctum', 'throttle:account-token-revoke'], ['tokenId' => '[0-9]+'],
            ],
        ], $actualRoutes);
    }

    public function test_auth_route_phases_remain_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'POST api/convolab/browser/tools/analytics',
            'POST api/auth/register',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'POST api/convolab/browser/auth/verification/send',
            'GET|HEAD api/me',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'POST api/convolab/auth/verification/send',
            'GET|HEAD api/convolab/admin/stats',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'PATCH api/feature-flags',
            'PUT api/me',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/auth/tokens/{tokenId}',
            'GET|HEAD api/courses',
        );
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
