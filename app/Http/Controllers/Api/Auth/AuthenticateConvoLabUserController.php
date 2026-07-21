<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\AuthenticateConvoLabUserAction;
use App\Domain\Auth\Exceptions\InvalidConvoLabCredentialsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AuthenticateConvoLabUserRequest;
use App\Http\Resources\Auth\ConvoLabAccountResource;
use Illuminate\Http\JsonResponse;

final class AuthenticateConvoLabUserController extends Controller
{
    public function __invoke(
        AuthenticateConvoLabUserRequest $request,
        AuthenticateConvoLabUserAction $action,
    ): JsonResponse {
        try {
            $projection = $action->handle(
                $request->validated('email'),
                $request->validated('password'),
            );
        } catch (InvalidConvoLabCredentialsException $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return response()->json(ConvoLabAccountResource::make($projection)->resolve($request));
    }
}
