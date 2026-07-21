<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\ShowConvoLabCurrentUserAction;
use App\Domain\Auth\Exceptions\VerifiedConvoLabAccountException;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendConvoLabVerificationRequest;
use App\Jobs\SendConvoLabVerificationEmail;
use Illuminate\Http\JsonResponse;

final class SendConvoLabVerificationController extends Controller
{
    public function __invoke(
        SendConvoLabVerificationRequest $request,
        ShowConvoLabCurrentUserAction $showCurrentUser,
    ): JsonResponse {
        $account = $showCurrentUser->handle(
            $request->convoLabUserId(),
            ConvoLabAccountSource::LEARNING_OS,
        );
        if ($account->email_verified) {
            $exception = new VerifiedConvoLabAccountException;

            return response()->json(['message' => $exception->getMessage()], 400);
        }

        SendConvoLabVerificationEmail::dispatch((int) $account->user_id);

        return response()->json(['message' => 'Verification email sent']);
    }
}
