<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\ClaimConvoLabGoogleInviteAction;
use App\Domain\Auth\Exceptions\ConvoLabOAuthException;
use App\Domain\Auth\Exceptions\ConvoLabSignupException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ClaimConvoLabGoogleInviteRequest;
use App\Http\Resources\Auth\ConvoLabCurrentUserResource;
use Illuminate\Http\JsonResponse;

final class ClaimConvoLabGoogleInviteController extends Controller
{
    public function __invoke(
        ClaimConvoLabGoogleInviteRequest $request,
        ClaimConvoLabGoogleInviteAction $action,
    ): JsonResponse {
        try {
            $account = $action->handle(
                $request->convoLabUserId(),
                $request->validated('inviteCode'),
            );
        } catch (ConvoLabSignupException|ConvoLabOAuthException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], $exception->status());
        }

        return response()->json(ConvoLabCurrentUserResource::make($account)->resolve($request));
    }
}
