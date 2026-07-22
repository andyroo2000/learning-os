<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\ResolveConvoLabUserAction;
use App\Domain\Auth\Actions\UpdateCurrentUserPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateConvoLabCurrentUserPasswordRequest;
use Illuminate\Http\Response;

final class UpdateConvoLabCurrentUserPasswordController extends Controller
{
    public function __invoke(
        UpdateConvoLabCurrentUserPasswordRequest $request,
        ResolveConvoLabUserAction $resolveUser,
        UpdateCurrentUserPasswordAction $updatePassword,
    ): Response {
        $data = $request->validated();
        $user = $resolveUser->handle($request->convoLabUserId());

        $updatePassword->handle($user, $data['current_password'], $data['password']);

        return response()->noContent();
    }
}
