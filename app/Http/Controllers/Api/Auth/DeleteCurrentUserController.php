<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\DeleteCurrentUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DeleteCurrentUserRequest;
use App\Models\User;
use Illuminate\Http\Response;

final class DeleteCurrentUserController extends Controller
{
    public function __invoke(DeleteCurrentUserRequest $request, DeleteCurrentUserAction $deleteUser): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $data = $request->validated();

        $deleteUser->handle($user, $data['current_password']);

        return response()->noContent();
    }
}
