<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\ClaimConvoLabGoogleInviteAction;
use App\Domain\Auth\Actions\StartConvoLabBrowserSessionAction;
use App\Domain\Auth\Exceptions\ConvoLabOAuthException;
use App\Domain\Auth\Exceptions\ConvoLabSignupException;
use App\Domain\Auth\Support\ConvoLabBrowserOAuthSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ClaimConvoLabBrowserGoogleInviteRequest;
use App\Http\Resources\Auth\ConvoLabCurrentUserResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

final class ClaimConvoLabBrowserGoogleInviteController extends Controller
{
    public function __invoke(
        ClaimConvoLabBrowserGoogleInviteRequest $request,
        ClaimConvoLabGoogleInviteAction $claimInvite,
        StartConvoLabBrowserSessionAction $startSession,
    ): JsonResponse {
        $convoLabUserId = ConvoLabBrowserOAuthSession::pending($request);
        if ($convoLabUserId === null) {
            return response()->json([
                'message' => 'Google sign-in has expired',
                'reason' => 'oauth_session_expired',
            ], 401);
        }

        try {
            $account = $claimInvite->handle(
                $convoLabUserId,
                $request->validated('inviteCode'),
            );
        } catch (ConvoLabSignupException|ConvoLabOAuthException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], $exception->status());
        } catch (ModelNotFoundException) {
            ConvoLabBrowserOAuthSession::forget($request);

            return response()->json([
                'message' => 'Google sign-in has expired',
                'reason' => 'oauth_session_expired',
            ], 401);
        }

        ConvoLabBrowserOAuthSession::forget($request);
        $startSession->handle($request, $account);

        return response()->json(
            ConvoLabCurrentUserResource::make($account)->resolve($request),
        );
    }
}
