<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\RegisterMobileUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterMobileUserRequest;
use App\Http\Resources\Auth\CurrentUserResource;
use Illuminate\Http\JsonResponse;

class RegisterMobileUserController extends Controller
{
    public function __invoke(RegisterMobileUserRequest $request, RegisterMobileUserAction $registerMobileUser): JsonResponse
    {
        $data = $request->validated();

        $result = $registerMobileUser->handle(
            name: $data['name'],
            email: $data['email'],
            password: $data['password'],
            deviceName: $data['device_name'],
        );

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
