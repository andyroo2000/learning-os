<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\CreateAdminInviteCodeAction;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminInviteCodeRequest;
use App\Http\Resources\Admin\AdminInviteCodeResource;
use Illuminate\Http\JsonResponse;

final class CreateAdminInviteCodeController extends Controller
{
    public function __invoke(CreateAdminInviteCodeRequest $request, CreateAdminInviteCodeAction $action): JsonResponse
    {
        try {
            $invite = $action->handle($request->customCode());
        } catch (AdminMutationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status());
        }

        return response()->json(AdminInviteCodeResource::make($invite)->resolve($request));
    }
}
