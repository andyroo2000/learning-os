<?php

namespace Tests\Feature\Study;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StudyCardLibraryRouteContractTest extends TestCase
{
    private const CARD_ID_PATTERN = '(?:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}|[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12})';

    public function test_study_card_library_routes_preserve_registration_order_names_actions_middleware_and_constraints(): void
    {
        $actions = [
            'ListStudyNewCardQueueController',
            'ListStudyCardsController',
            'ListStudyLearningItemsController',
            'ListStudyCardBatchController',
            'ShowStudyCardController',
            'ReorderStudyNewCardQueueController',
        ];

        $actualRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => in_array(
                class_basename($route->getActionName()),
                $actions,
                strict: true,
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

        $this->assertSame([
            $this->expectedRoute('GET|HEAD', 'api/study/new-queue', 'ListStudyNewCardQueueController'),
            $this->expectedRoute('GET|HEAD', 'api/study/cards', 'ListStudyCardsController'),
            $this->expectedRoute(
                'GET|HEAD',
                'api/study/learning-items',
                'ListStudyLearningItemsController',
            ),
            $this->expectedRoute('POST', 'api/study/cards/batch', 'ListStudyCardBatchController'),
            $this->expectedRoute(
                'GET|HEAD',
                'api/study/cards/{cardId}',
                'ShowStudyCardController',
                ['cardId' => self::CARD_ID_PATTERN],
            ),
            $this->expectedRoute(
                'POST',
                'api/study/new-queue/reorder',
                'ReorderStudyNewCardQueueController',
                rateLimitMiddleware: 'throttle:new-card-queue-reorder',
            ),
        ], $actualRoutes);
    }

    public function test_study_card_library_routes_remain_inside_the_network_limit_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/study/card-drafts/{draftId}',
            'GET|HEAD api/study/new-queue',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'POST api/study/new-queue/reorder',
            'GET|HEAD api/study/overview',
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
        array $wheres = [],
        string $rateLimitMiddleware = 'throttle:study-compatibility-read',
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
