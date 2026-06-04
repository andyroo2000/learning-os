<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreMobileTokenRequest;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class StoreMobileTokenController extends Controller
{
    public function __invoke(StoreMobileTokenRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()
            ->where('email', $data['email'])
            ->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $expiresAt = $this->tokenExpiresAt();
        $token = $user->createToken($data['device_name'], ['*'], $expiresAt);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt?->toJSON(),
            ],
        ], 201);
    }

    private function tokenExpiresAt(): ?DateTimeInterface
    {
        $expirationMinutes = config('sanctum.expiration');

        if (! is_numeric($expirationMinutes) || (int) $expirationMinutes < 1) {
            return null;
        }

        return now()->addMinutes((int) $expirationMinutes);
    }
}
