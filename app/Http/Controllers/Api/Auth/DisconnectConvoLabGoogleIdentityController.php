<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\DisconnectConvoLabGoogleIdentityAction;
use App\Domain\Auth\Exceptions\ConvoLabOAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DisconnectConvoLabGoogleIdentityRequest;
use Illuminate\Http\JsonResponse;

final class DisconnectConvoLabGoogleIdentityController extends Controller
{
    public function __invoke(
        DisconnectConvoLabGoogleIdentityRequest $request,
        DisconnectConvoLabGoogleIdentityAction $action,
    ): JsonResponse {
        try {
            $action->handle($request->convoLabUserId());
        } catch (ConvoLabOAuthException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], $exception->status());
        }

        return response()->json([
            'success' => true,
            'message' => 'Google account disconnected',
        ]);
    }
}
