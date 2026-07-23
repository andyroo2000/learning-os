<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\VerifyConvoLabEmailAction;
use App\Domain\Auth\Exceptions\InvalidConvoLabVerificationTokenException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyConvoLabBrowserEmailRequest;
use Illuminate\Http\JsonResponse;

final class VerifyConvoLabBrowserEmailController extends Controller
{
    public function __invoke(
        VerifyConvoLabBrowserEmailRequest $request,
        VerifyConvoLabEmailAction $verifyEmail,
    ): JsonResponse {
        try {
            $account = $verifyEmail->handle($request->token());
        } catch (InvalidConvoLabVerificationTokenException $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }

        return response()->json([
            'message' => 'Email verified successfully',
            'email' => $account->email,
        ]);
    }
}
