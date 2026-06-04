<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\UpdateCurrentUserProfileAction;
use App\Domain\Auth\Exceptions\DuplicateUserEmailException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateCurrentUserProfileRequest;
use App\Http\Resources\Auth\CurrentUserResource;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UpdateCurrentUserProfileController extends Controller
{
    public function __invoke(UpdateCurrentUserProfileRequest $request, UpdateCurrentUserProfileAction $updateProfile): CurrentUserResource
    {
        $user = $request->user();

        // Narrow the auth contract before updating app-owned profile fields.
        abort_unless($user instanceof User, 401);

        $data = $request->validated();

        try {
            $updatedUser = $updateProfile->handle(
                user: $user,
                name: $data['name'],
                email: $data['email'],
            );
        } catch (DuplicateUserEmailException $exception) {
            throw ValidationException::withMessages([
                'email' => [$exception->getMessage()],
            ]);
        }

        return CurrentUserResource::make($updatedUser);
    }
}
