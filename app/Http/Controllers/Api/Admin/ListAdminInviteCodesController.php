<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\ListAdminInviteCodesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminReadRequest;
use App\Http\Resources\Admin\AdminInviteCodeResource;
use Illuminate\Http\JsonResponse;

class ListAdminInviteCodesController extends Controller
{
    public function __invoke(
        ConvoLabAdminReadRequest $request,
        ListAdminInviteCodesAction $action,
    ): JsonResponse {
        return response()->json(
            AdminInviteCodeResource::collection($action->handle())->resolve($request),
        );
    }
}
