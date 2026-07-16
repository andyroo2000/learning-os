<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Japanese\Actions\SetManualKnownKanjiAction;
use App\Domain\Japanese\Actions\ShowKnownKanjiAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\SetManualKnownKanjiRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class SetManualKnownKanjiController extends Controller
{
    public function __invoke(
        SetManualKnownKanjiRequest $request,
        SetManualKnownKanjiAction $setManual,
        ShowKnownKanjiAction $show,
    ): JsonResponse {
        $userId = AuthenticatedUser::id($request);
        $setManual->handle($userId, $request->kanji(), $request->known());

        return response()->json($show->handle($userId));
    }
}
