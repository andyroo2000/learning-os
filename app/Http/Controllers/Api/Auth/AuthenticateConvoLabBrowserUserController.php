<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\AuthenticateConvoLabUserAction;
use App\Domain\Auth\Actions\StartConvoLabBrowserSessionAction;
use App\Domain\Auth\Exceptions\InvalidConvoLabCredentialsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AuthenticateConvoLabBrowserUserRequest;
use App\Http\Resources\Auth\ConvoLabAccountResource;
use Illuminate\Http\JsonResponse;

final class AuthenticateConvoLabBrowserUserController extends Controller
{
    public function __invoke(
        AuthenticateConvoLabBrowserUserRequest $request,
        AuthenticateConvoLabUserAction $authenticate,
        StartConvoLabBrowserSessionAction $startSession,
    ): JsonResponse {
        try {
            $account = $authenticate->handle(
                $request->validated('email'),
                $request->validated('password'),
            );
        } catch (InvalidConvoLabCredentialsException $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        $startSession->handle($request, $account);

        return response()->json(ConvoLabAccountResource::make($account)->resolve($request));
    }
}
