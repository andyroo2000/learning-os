<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\ResolveConvoLabGoogleIdentityAction;
use App\Domain\Auth\Exceptions\ConvoLabOAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResolveConvoLabGoogleIdentityRequest;
use App\Http\Resources\Auth\ConvoLabAccountResource;
use Illuminate\Http\JsonResponse;

final class ResolveConvoLabGoogleIdentityController extends Controller
{
    public function __invoke(
        ResolveConvoLabGoogleIdentityRequest $request,
        ResolveConvoLabGoogleIdentityAction $action,
    ): JsonResponse {
        $data = $request->validated();
        try {
            $result = $action->handle(
                providerId: $data['providerId'],
                email: $data['email'],
                name: $data['name'],
                avatarUrl: $data['avatarUrl'] ?? null,
                emailVerified: $data['emailVerified'],
            );
        } catch (ConvoLabOAuthException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], $exception->status());
        }

        return response()->json([
            'user' => ConvoLabAccountResource::make($result->account)->resolve($request),
            'requiresInvite' => $result->requiresInvite,
            'created' => $result->created,
        ]);
    }
}
