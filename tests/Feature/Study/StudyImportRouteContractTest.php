<?php

namespace Tests\Feature\Study;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StudyImportRouteContractTest extends TestCase
{
    private const IMPORT_JOB_ID_PATTERN = '(?:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}|[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12})';

    private const ULID_PATTERN = '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}';

    public function test_study_import_routes_preserve_registration_order_names_actions_middleware_and_constraints(): void
    {
        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => $route->uri() === 'api/study/imports'
                || str_starts_with($route->uri(), 'api/study/imports/'))
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
                'GET|HEAD',
                'api/study/imports',
                'ListStudyImportJobsController',
                'throttle:study-compatibility-read',
            ),
            $this->expectedRoute(
                'POST',
                'api/study/imports',
                'StoreStudyImportController',
                'throttle:study-import-create',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/study/imports/readiness',
                'ShowStudyImportReadinessController',
                'throttle:study-compatibility-read',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/study/imports/current',
                'ShowCurrentStudyImportJobController',
                'throttle:study-compatibility-read',
            ),
            $this->expectedRoute(
                'PUT',
                'api/study/imports/{studyImportJobId}/upload',
                'UploadStudyImportFileController',
                'throttle:study-import-upload',
                'api.study.imports.upload',
                ['studyImportJobId' => self::ULID_PATTERN],
            ),
            $this->expectedRoute(
                'POST',
                'api/study/imports/{studyImportJobId}/complete',
                'CompleteStudyImportUploadController',
                'throttle:study-import-complete',
                wheres: ['studyImportJobId' => self::ULID_PATTERN],
            ),
            $this->expectedRoute(
                'POST',
                'api/study/imports/{studyImportJobId}/cancel',
                'CancelStudyImportUploadController',
                'throttle:study-import-cancel',
                wheres: ['studyImportJobId' => self::ULID_PATTERN],
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/study/imports/{studyImportJobId}',
                'ShowStudyImportJobController',
                'throttle:study-compatibility-read',
                wheres: ['studyImportJobId' => self::IMPORT_JOB_ID_PATTERN],
            ),
        ], $actualRoutes);
    }

    public function test_study_import_routes_remain_inside_the_network_limit_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'GET|HEAD api/study/export/settings',
            'GET|HEAD api/study/imports',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'GET|HEAD api/study/imports/{studyImportJobId}',
            'GET|HEAD api/study/browser',
        );
    }

    /**
     * @param  array<string, string>  $wheres
     * @return array{
     *     methods: string,
     *     uri: string,
     *     name: ?string,
     *     action: string,
     *     middleware: list<string>,
     *     wheres: array<string, string>
     * }
     */
    private function expectedRoute(
        string $methods,
        string $uri,
        string $action,
        string $rateLimitMiddleware,
        ?string $name = null,
        array $wheres = [],
    ): array {
        return [
            'methods' => $methods,
            'uri' => $uri,
            'name' => $name,
            'action' => $action,
            'middleware' => [
                'api',
                'auth:sanctum',
                'throttle:study-compatibility-network',
                $rateLimitMiddleware,
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
