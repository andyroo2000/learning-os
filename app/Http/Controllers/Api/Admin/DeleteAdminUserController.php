<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\DeleteAdminUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminWriteRequest;
use Illuminate\Http\JsonResponse;

final class DeleteAdminUserController extends Controller
{
    public function __invoke(ConvoLabAdminWriteRequest $request, string $convoLabUserId, DeleteAdminUserAction $action): JsonResponse
    {
        $action->handle($request->actorConvoLabUserId(), $convoLabUserId);

        return response()->json(['message' => 'User deleted successfully']);
    }
}
