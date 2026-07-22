<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\DeleteCurrentUserAction;
use App\Domain\Auth\Actions\ResolveConvoLabUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DeleteConvoLabCurrentUserRequest;
use Illuminate\Http\Response;

final class DeleteConvoLabCurrentUserController extends Controller
{
    public function __invoke(
        DeleteConvoLabCurrentUserRequest $request,
        ResolveConvoLabUserAction $resolveUser,
        DeleteCurrentUserAction $deleteUser,
    ): Response {
        $data = $request->validated();
        $user = $resolveUser->handle($request->convoLabUserId());

        $deleteUser->handle($user, $data['current_password']);

        return response()->noContent();
    }
}
