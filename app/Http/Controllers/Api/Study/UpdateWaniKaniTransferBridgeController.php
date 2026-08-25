<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Japanese\Actions\ShowKnownKanjiAction;
use App\Domain\Japanese\Actions\UpdateWaniKaniTransferBridgeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\UpdateWaniKaniTransferBridgeRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class UpdateWaniKaniTransferBridgeController extends Controller
{
    public function __invoke(
        UpdateWaniKaniTransferBridgeRequest $request,
        UpdateWaniKaniTransferBridgeAction $update,
        ShowKnownKanjiAction $show,
    ): JsonResponse {
        $userId = AuthenticatedUser::id($request);
        $update->handle($userId, $request->enabled());

        return response()->json($show->handle($userId));
    }
}
