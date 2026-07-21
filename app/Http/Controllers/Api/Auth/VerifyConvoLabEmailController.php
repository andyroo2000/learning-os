<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\VerifyConvoLabEmailAction;
use App\Domain\Auth\Exceptions\InvalidConvoLabVerificationTokenException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyConvoLabEmailRequest;
use Illuminate\Http\JsonResponse;

final class VerifyConvoLabEmailController extends Controller
{
    public function __invoke(
        VerifyConvoLabEmailRequest $request,
        VerifyConvoLabEmailAction $action,
    ): JsonResponse {
        try {
            $account = $action->handle($request->token());
        } catch (InvalidConvoLabVerificationTokenException $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }

        return response()->json([
            'message' => 'Email verified successfully',
            'email' => $account->email,
        ]);
    }
}
