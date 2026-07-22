<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\DeleteAdminUserAction;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminWriteRequest;
use Illuminate\Http\JsonResponse;

final class DeleteAdminUserController extends Controller
{
    public function __invoke(ConvoLabAdminWriteRequest $request, string $convoLabUserId, DeleteAdminUserAction $action): JsonResponse
    {
        try {
            $action->handle($request->actorConvoLabUserId(), $convoLabUserId);
        } catch (AdminMutationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status());
        }

        return response()->json(['message' => 'User deleted successfully']);
    }
}
