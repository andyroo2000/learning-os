<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Auth\Actions\SendConvoLabBetaAccessRequestAction;
use App\Http\Requests\Auth\RequestConvoLabBetaAccessRequest;
use Illuminate\Http\JsonResponse;

final class RequestConvoLabBetaAccessController
{
    public function __invoke(RequestConvoLabBetaAccessRequest $request, SendConvoLabBetaAccessRequestAction $send): JsonResponse
    {
        $data = $request->validated();
        $send->execute($data['email'], $data['devices'], $data['message'] ?? '');

        return response()->json(['message' => 'Your beta access request was received.']);
    }
}
