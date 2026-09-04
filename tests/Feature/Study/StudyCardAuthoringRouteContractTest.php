<?php

namespace Tests\Feature\Study;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StudyCardAuthoringRouteContractTest extends TestCase
{
    private const CARD_ID_PATTERN = '(?:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}|[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12})';

    private const ULID_PATTERN = '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}';

    public function test_study_card_authoring_routes_preserve_registration_order_names_actions_middleware_and_constraints(): void
    {
        $actualRoutes = $this->actualAuthoringRoutes();
        $draftIdWhere = ['draftId' => self::ULID_PATTERN];
        $cardIdWhere = ['cardId' => self::CARD_ID_PATTERN];

        $this->assertSame(array_merge(
            $this->expectedDraftRoutes($draftIdWhere),
            $this->expectedCardRoutes($cardIdWhere),
            $this->expectedFinalDraftRoutes($draftIdWhere),
        ), $actualRoutes);
    }

    /** @return list<array<string, mixed>> */
    private function actualAuthoringRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(static function (LaravelRoute $route): bool {
                $uri = $route->uri();

                return $uri === 'api/study/card-drafts'
                    || str_starts_with($uri, 'api/study/card-drafts/')
                    || $uri === 'api/study/card-candidates/vocab-bundle/drafts'
                    || preg_match(
                        '#^api/study/cards/\{cardId\}/(?:regenerate-answer-audio|regenerate-image|image|pitch-accent|prepare-answer-audio)$#',
                        $uri,
                    ) === 1;
            })
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

    /** @param array<string, string> $draftIdWhere */
    private function expectedDraftRoutes(array $draftIdWhere): array
    {
        return [
            $this->expectedRoute(
                'GET|HEAD',
                'api/study/card-drafts',
                'ListStudyCardDraftsController',
                'throttle:study-compatibility-read',
            ),
            $this->expectedRoute(
                'GET|HEAD',
                'api/study/card-drafts/{draftId}',
                'ShowStudyCardDraftController',
                'throttle:study-compatibility-read',
                $draftIdWhere,
            ),
            $this->expectedRoute(
                'POST',
                'api/study/card-drafts/{draftId}/card',
                'StoreStudyCardFromDraftController',
                'throttle:study-card-create',
                $draftIdWhere,
            ),
            $this->expectedRoute(
                'POST',
                'api/study/card-drafts/{draftId}/create-card',
                'StoreStudyCardFromDraftController',
                'throttle:study-card-create',
                $draftIdWhere,
            ),
            $this->expectedRoute(
                'POST',
                'api/study/card-drafts',
                'StoreStudyCardDraftController',
                'throttle:study-card-create',
            ),
            $this->expectedRoute(
                'POST',
                'api/study/card-candidates/vocab-bundle/drafts',
                'StoreStudyVocabBundleDraftsController',
                'throttle:study-vocab-bundle-drafts',
            ),
            $this->expectedRoute(
                'PATCH',
                'api/study/card-drafts/{draftId}',
                'UpdateStudyCardDraftController',
                'throttle:study-card-draft-autosave',
                $draftIdWhere,
            ),
            $this->expectedRoute(
                'POST',
                'api/study/card-drafts/{draftId}/preview-audio',
                'GenerateStudyCardDraftPreviewAudioController',
                wheres: $draftIdWhere,
            ),
            $this->expectedRoute(
                'POST',
                'api/study/card-drafts/{draftId}/preview-image',
                'GenerateStudyCardDraftPreviewImageController',
                wheres: $draftIdWhere,
            ),
        ];
    }

    /** @param array<string, string> $cardIdWhere */
    private function expectedCardRoutes(array $cardIdWhere): array
    {
        return [
            $this->expectedRoute(
                'POST',
                'api/study/cards/{cardId}/regenerate-answer-audio',
                'RegenerateStudyCardAnswerAudioController',
                wheres: $cardIdWhere,
            ),
            $this->expectedRoute(
                'POST',
                'api/study/cards/{cardId}/regenerate-image',
                'RegenerateStudyCardImageController',
                wheres: $cardIdWhere,
            ),
            $this->expectedRoute(
                'POST',
                'api/study/cards/{cardId}/image',
                'UploadStudyCardImageController',
                'throttle:study-card-update',
                $cardIdWhere,
            ),
            $this->expectedRoute(
                'POST',
                'api/study/cards/{cardId}/pitch-accent',
                'ResolveStudyCardPitchAccentController',
                'throttle:study-card-pitch-accent',
                $cardIdWhere,
            ),
            $this->expectedRoute(
                'POST',
                'api/study/cards/{cardId}/prepare-answer-audio',
                'PrepareStudyCardAnswerAudioController',
                'throttle:study-card-audio-prepare',
                $cardIdWhere,
            ),
        ];
    }

    /** @param array<string, string> $draftIdWhere */
    private function expectedFinalDraftRoutes(array $draftIdWhere): array
    {
        return [
            $this->expectedRoute(
                'POST',
                'api/study/card-drafts/{draftId}/retry',
                'RetryStudyCardDraftController',
                'throttle:study-card-draft-retry',
                $draftIdWhere,
            ),
            $this->expectedRoute(
                'DELETE',
                'api/study/card-drafts/{draftId}',
                'DeleteStudyCardDraftController',
                'throttle:study-card-draft-delete',
                $draftIdWhere,
            ),
        ];
    }

    public function test_study_card_authoring_routes_remain_inside_the_network_limit_at_their_original_global_boundaries(): void
    {
        $routeOrder = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertImmediatelyBefore(
            $routeOrder,
            'GET|HEAD api/study/browser/{noteId}',
            'GET|HEAD api/study/card-drafts',
        );
        $this->assertImmediatelyBefore(
            $routeOrder,
            'DELETE api/study/card-drafts/{draftId}',
            'GET|HEAD api/study/new-queue',
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
        ?string $rateLimitMiddleware = null,
        array $wheres = [],
    ): array {
        $middleware = [
            'api',
            'auth:sanctum',
            'throttle:study-compatibility-network',
        ];

        if ($rateLimitMiddleware !== null) {
            $middleware[] = $rateLimitMiddleware;
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
