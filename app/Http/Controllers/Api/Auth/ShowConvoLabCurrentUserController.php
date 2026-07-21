<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\ShowConvoLabCurrentUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ShowConvoLabCurrentUserRequest;
use App\Http\Resources\Auth\ConvoLabCurrentUserResource;
use Illuminate\Http\JsonResponse;

final class ShowConvoLabCurrentUserController extends Controller
{
    public function __invoke(
        ShowConvoLabCurrentUserRequest $request,
        ShowConvoLabCurrentUserAction $action,
    ): JsonResponse {
        $projection = $action->handle($request->convoLabUserId());

        return response()->json(ConvoLabCurrentUserResource::make($projection)->resolve($request));
    }
}
