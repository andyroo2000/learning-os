<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\RegisterConvoLabMobileUserAction;
use App\Domain\Auth\Exceptions\ConvoLabSignupException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterConvoLabMobileUserRequest;
use App\Http\Resources\Auth\CurrentUserResource;
use App\Jobs\SendConvoLabVerificationEmail;
use Illuminate\Http\JsonResponse;

final class RegisterConvoLabMobileUserController extends Controller
{
    public function __invoke(
        RegisterConvoLabMobileUserRequest $request,
        RegisterConvoLabMobileUserAction $register,
    ): JsonResponse {
        $data = $request->validated();

        try {
            $result = $register->handle(
                name: $data['name'],
                email: $data['email'],
                password: $data['password'],
                inviteCode: $data['inviteCode'],
                deviceName: $data['device_name'],
            );
        } catch (ConvoLabSignupException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], $exception->status());
        }

        if (! $result->user->hasVerifiedEmail()) {
            SendConvoLabVerificationEmail::dispatch((int) $result->user->getKey());
        }

        return response()->json([
            'data' => [
                'user' => CurrentUserResource::make($result->user)->resolve($request),
                'token' => $result->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $result->expiresAt?->toJSON(),
            ],
        ], 201);
    }
}
