<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\ShowAdminUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminReadRequest;
use App\Http\Resources\Admin\AdminUserInfoResource;
use Illuminate\Http\JsonResponse;

class ShowAdminUserController extends Controller
{
    public function __invoke(
        ConvoLabAdminReadRequest $request,
        ShowAdminUserAction $action,
        string $convoLabUserId,
    ): JsonResponse {
        return response()->json(
            AdminUserInfoResource::make($action->handle($convoLabUserId))->resolve($request),
        );
    }
}
