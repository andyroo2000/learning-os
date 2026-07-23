<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Exceptions\VerifiedConvoLabAccountException;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendConvoLabBrowserVerificationRequest;
use App\Jobs\SendConvoLabVerificationEmail;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class SendConvoLabBrowserVerificationController extends Controller
{
    public function __invoke(
        SendConvoLabBrowserVerificationRequest $request,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user('web');
        $account = $user->adminUserProjection()
            ->where('source_system', ConvoLabAccountSource::LEARNING_OS)
            ->firstOrFail();

        if ($account->email_verified) {
            $exception = new VerifiedConvoLabAccountException;

            return response()->json(['message' => $exception->getMessage()], 400);
        }

        SendConvoLabVerificationEmail::dispatch((int) $account->user_id);

        return response()->json(['message' => 'Verification email sent']);
    }
}
