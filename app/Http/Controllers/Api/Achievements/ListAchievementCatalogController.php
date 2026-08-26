<?php

namespace App\Http\Controllers\Api\Achievements;

use App\Domain\Achievements\Actions\GetAchievementCatalogAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ListAchievementCatalogController extends Controller
{
    public function __invoke(GetAchievementCatalogAction $action): JsonResponse
    {
        $response = response()->json([
            ...$action->handle(),
            'assetBaseUrl' => rtrim((string) config('app.url'), '/'),
        ]);

        $response->headers->set('Cache-Control', 'public, max-age=300, stale-while-revalidate=86400');

        return $response;
    }
}
