<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Content\Actions\ManageContentGenerationQuotaAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ShowConvoLabGenerationQuotaRequest;
use Illuminate\Http\JsonResponse;

final class ShowConvoLabGenerationQuotaController extends Controller
{
    public function __invoke(
        ShowConvoLabGenerationQuotaRequest $request,
        ManageContentGenerationQuotaAction $quota,
    ): JsonResponse {
        $status = $quota->status($request->convoLabUserId());

        return response()->json([
            'unlimited' => $status->unlimited,
            'quota' => $status->quotaPayload(),
            'cooldown' => $status->cooldownPayload(),
        ]);
    }
}
