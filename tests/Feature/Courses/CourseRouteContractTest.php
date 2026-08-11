<?php

namespace Tests\Feature\Courses;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CourseRouteContractTest extends TestCase
{
    public function test_api_course_routes_preserve_registration_order_actions_middleware_and_constraints(): void
    {
        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => $route->uri() === 'api/courses'
                || str_starts_with($route->uri(), 'api/courses/'))
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

        $ulid = '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}';

        $this->assertSame([
            $this->expectedRoute('GET|HEAD', 'api/courses', 'ListCoursesController'),
            $this->expectedRoute(
                'POST',
                'api/courses',
                'StoreCourseController',
                'course-create',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/courses/{course}',
                'ShowCourseController',
                wheres: ['course' => $ulid],
            ),
            $this->expectedRoute(
                'PUT',
                'api/courses/{course}',
                'UpdateCourseController',
                'course-update',
                ['course' => $ulid],
            ),
            $this->expectedRoute(
                'DELETE',
                'api/courses/{course}',
                'DeleteCourseController',
                'course-delete',
                ['course' => $ulid],
            ),
        ], $actualRoutes);
    }

    public function test_course_routes_remain_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/auth/tokens/{tokenId}',
            'GET|HEAD api/courses',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/courses/{course}',
            'GET|HEAD api/card-review-events',
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
