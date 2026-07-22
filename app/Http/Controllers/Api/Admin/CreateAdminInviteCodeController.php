<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\CreateAdminInviteCodeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminInviteCodeRequest;
use App\Http\Resources\Admin\AdminInviteCodeResource;
use Illuminate\Http\JsonResponse;

final class CreateAdminInviteCodeController extends Controller
{
    public function __invoke(CreateAdminInviteCodeRequest $request, CreateAdminInviteCodeAction $action): JsonResponse
    {
        $invite = $action->handle($request->customCode());

        return response()->json(AdminInviteCodeResource::make($invite)->resolve($request));
    }
}
