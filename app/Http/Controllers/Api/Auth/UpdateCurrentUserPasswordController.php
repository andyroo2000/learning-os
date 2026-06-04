<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\UpdateCurrentUserPasswordAction;
use App\Domain\Auth\Exceptions\InvalidCurrentPasswordException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateCurrentUserPasswordRequest;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class UpdateCurrentUserPasswordController extends Controller
{
    public function __invoke(UpdateCurrentUserPasswordRequest $request, UpdateCurrentUserPasswordAction $updatePassword): Response
    {
        $user = $request->user();

        // Narrow the auth contract before reading and updating the app user password.
        abort_unless($user instanceof User, 401);

        $data = $request->validated();

        try {
            $updatePassword->handle(
                user: $user,
                currentPassword: $data['current_password'],
                password: $data['password'],
            );
        } catch (InvalidCurrentPasswordException $exception) {
            throw ValidationException::withMessages([
                'current_password' => [$exception->getMessage()],
            ]);
        }

        return response()->noContent();
    }
}
