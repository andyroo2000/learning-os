<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\CreateBetaInvitationAction;
use App\Domain\Admin\Actions\SendBetaInvitationAction;
use App\Http\Requests\Admin\CreateBetaInvitationRequest;
use App\Http\Resources\Admin\BetaRequestResource;
use Illuminate\Http\JsonResponse;

final class CreateBetaInvitationController
{
    public function __invoke(CreateBetaInvitationRequest $request, CreateBetaInvitationAction $create, SendBetaInvitationAction $send): JsonResponse
    {
        $data = $request->validated();
        $record = $create->handle($data['email'], $request->actorConvoLabUserId());
        $sent = $send->handle($record);

        return response()->json(BetaRequestResource::make($sent)->resolve($request));
    }
}
