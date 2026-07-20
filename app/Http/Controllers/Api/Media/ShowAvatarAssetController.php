<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Media\Actions\ResolveAvatarAssetAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class ShowAvatarAssetController extends Controller
{
    public function __invoke(
        string $avatarPath,
        ResolveAvatarAssetAction $resolveAvatarAsset,
    ): RedirectResponse|JsonResponse {
        $redirect = $resolveAvatarAsset->handle($avatarPath);
        if ($redirect === null) {
            return response()->json(['error' => 'Avatar not found'], 404);
        }

        $response = redirect()->to($redirect->location, 302);
        if ($redirect->cachePrivately) {
            $response->headers->set('Cache-Control', 'private, max-age=300');
        }

        return $response;
    }
}
