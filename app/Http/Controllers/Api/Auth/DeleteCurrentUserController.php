<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\DeleteCurrentUserAction;
use App\Domain\Auth\Exceptions\InvalidCurrentPasswordException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DeleteCurrentUserRequest;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

final class DeleteCurrentUserController extends Controller
{
    public function __invoke(DeleteCurrentUserRequest $request, DeleteCurrentUserAction $deleteUser): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $data = $request->validated();

        try {
            $deleteUser->handle($user, $data['current_password']);
        } catch (InvalidCurrentPasswordException $exception) {
            throw ValidationException::withMessages([
                'current_password' => [$exception->getMessage()],
            ]);
        }

        return response()->noContent();
    }
}
