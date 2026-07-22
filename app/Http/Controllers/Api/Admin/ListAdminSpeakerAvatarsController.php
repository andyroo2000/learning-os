<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\ListAdminSpeakerAvatarsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminReadRequest;
use App\Http\Resources\Admin\AdminSpeakerAvatarResource;
use Illuminate\Http\JsonResponse;

class ListAdminSpeakerAvatarsController extends Controller
{
    public function __invoke(
        ConvoLabAdminReadRequest $request,
        ListAdminSpeakerAvatarsAction $action,
    ): JsonResponse {
        return response()
            ->json(AdminSpeakerAvatarResource::collection($action->handle())->resolve($request))
            ->header('Cache-Control', 'public, max-age=3600, s-maxage=86400');
    }
}
