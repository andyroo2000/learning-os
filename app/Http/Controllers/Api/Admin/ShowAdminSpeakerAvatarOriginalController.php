<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\ShowAdminSpeakerAvatarOriginalAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShowAdminSpeakerAvatarRequest;
use Illuminate\Http\JsonResponse;

class ShowAdminSpeakerAvatarOriginalController extends Controller
{
    public function __invoke(
        ShowAdminSpeakerAvatarRequest $request,
        ShowAdminSpeakerAvatarOriginalAction $action,
    ): JsonResponse {
        return response()->json(['originalUrl' => $action->handle($request->filename())]);
    }
}
