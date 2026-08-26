<?php

namespace App\Http\Controllers\Api\Achievements;

use App\Domain\Achievements\Actions\GetAchievementCatalogAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListAchievementCatalogController extends Controller
{
    public function __invoke(Request $request, GetAchievementCatalogAction $action): JsonResponse
    {
        $response = response()->json([
            ...$action->handle(),
            'assetBaseUrl' => $request->getSchemeAndHttpHost(),
        ]);

        $response->headers->set('Cache-Control', 'public, max-age=300, stale-while-revalidate=86400');

        return $response;
    }
}
