<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\DeleteAdminInviteCodeAction;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminWriteRequest;
use Illuminate\Http\JsonResponse;

final class DeleteAdminInviteCodeController extends Controller
{
    public function __invoke(ConvoLabAdminWriteRequest $request, string $inviteId, DeleteAdminInviteCodeAction $action): JsonResponse
    {
        try {
            $action->handle($inviteId);
        } catch (AdminMutationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status());
        }

        return response()->json(['message' => 'Invite code deleted successfully']);
    }
}
