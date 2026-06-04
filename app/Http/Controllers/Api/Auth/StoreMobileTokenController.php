<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\IssueMobileTokenAction;
use App\Domain\Auth\Exceptions\InvalidMobileTokenCredentialsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreMobileTokenRequest;
use Illuminate\Http\JsonResponse;

class StoreMobileTokenController extends Controller
{
    public function __invoke(StoreMobileTokenRequest $request, IssueMobileTokenAction $issueMobileToken): JsonResponse
    {
        $data = $request->validated();

        try {
            $result = $issueMobileToken->handle(
                email: $data['email'],
                password: $data['password'],
                deviceName: $data['device_name'],
            );
        } catch (InvalidMobileTokenCredentialsException $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return response()->json([
            'data' => [
                'token' => $result->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $result->expiresAt?->toJSON(),
            ],
        ], 201);
    }
}
