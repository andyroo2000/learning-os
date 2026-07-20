<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Media\Actions\ResolveToolAudioUrlsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\ResolveToolAudioUrlsRequest;
use Illuminate\Http\JsonResponse;

final class ResolveToolAudioUrlsController extends Controller
{
    public function __invoke(
        ResolveToolAudioUrlsRequest $request,
        ResolveToolAudioUrlsAction $resolveToolAudioUrls,
    ): JsonResponse {
        return response()->json($resolveToolAudioUrls->handle($request->paths()));
    }
}
