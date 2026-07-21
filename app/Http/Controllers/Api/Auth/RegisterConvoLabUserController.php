<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\RegisterConvoLabUserAction;
use App\Domain\Auth\Exceptions\ConvoLabSignupException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterConvoLabUserRequest;
use App\Http\Resources\Auth\ConvoLabAccountResource;
use App\Jobs\SendConvoLabVerificationEmail;
use Illuminate\Http\JsonResponse;

final class RegisterConvoLabUserController extends Controller
{
    public function __invoke(
        RegisterConvoLabUserRequest $request,
        RegisterConvoLabUserAction $action,
    ): JsonResponse {
        $data = $request->validated();

        try {
            $result = $action->handle(
                email: $data['email'],
                password: $data['password'],
                name: $data['name'],
                inviteCode: $data['inviteCode'],
            );
        } catch (ConvoLabSignupException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], $exception->status());
        }

        if (! $result->account->email_verified) {
            SendConvoLabVerificationEmail::dispatch((int) $result->account->user_id);
        }

        return response()->json(
            ConvoLabAccountResource::make($result->account)->resolve($request),
        );
    }
}
