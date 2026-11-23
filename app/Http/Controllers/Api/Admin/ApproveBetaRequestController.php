<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\ApproveBetaRequestAction;
use App\Domain\Admin\Actions\SendBetaInvitationAction;
use App\Http\Requests\Admin\ConvoLabAdminWriteRequest;
use App\Http\Resources\Admin\BetaRequestResource;
use Illuminate\Http\JsonResponse;

final class ApproveBetaRequestController
{
    public function __invoke(ConvoLabAdminWriteRequest $request, string $betaRequestId, ApproveBetaRequestAction $approve, SendBetaInvitationAction $send): JsonResponse
    {
        $record = $approve->handle($betaRequestId, $request->actorConvoLabUserId());
        $sent = $send->handle($record);

        return response()->json(BetaRequestResource::make($sent)->resolve($request));
    }
}
