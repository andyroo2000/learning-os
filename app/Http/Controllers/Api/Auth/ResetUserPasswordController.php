<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\ResetUserPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetUserPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class ResetUserPasswordController extends Controller
{
    public function __invoke(ResetUserPasswordRequest $request, ResetUserPasswordAction $resetPassword): Response|JsonResponse
    {
        $status = $resetPassword->handle(
            email: $request->validated('email'),
            token: $request->validated('token'),
            password: $request->validated('password'),
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->noContent();
        }

        if ($status === Password::RESET_THROTTLED) {
            return response()->json(['message' => __($status)], 429);
        }

        throw ValidationException::withMessages([
            'token' => [__('passwords.token')],
        ]);
    }
}
