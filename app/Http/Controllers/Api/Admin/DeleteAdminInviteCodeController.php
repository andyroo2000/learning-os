<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\DeleteAdminInviteCodeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminWriteRequest;
use Illuminate\Http\JsonResponse;

final class DeleteAdminInviteCodeController extends Controller
{
    public function __invoke(ConvoLabAdminWriteRequest $request, string $inviteId, DeleteAdminInviteCodeAction $action): JsonResponse
    {
        $action->handle($inviteId);

        return response()->json(['message' => 'Invite code deleted successfully']);
    }
}
