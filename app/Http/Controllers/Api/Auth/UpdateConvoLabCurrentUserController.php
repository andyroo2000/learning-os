<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\UpdateConvoLabCurrentUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateConvoLabCurrentUserRequest;
use App\Http\Resources\Auth\ConvoLabCurrentUserResource;
use Illuminate\Http\JsonResponse;

final class UpdateConvoLabCurrentUserController extends Controller
{
    public function __invoke(
        UpdateConvoLabCurrentUserRequest $request,
        UpdateConvoLabCurrentUserAction $action,
    ): JsonResponse {
        $account = $action->handle($request->convoLabUserId(), $request->profileData());

        return response()->json(ConvoLabCurrentUserResource::make($account)->resolve($request));
    }
}
