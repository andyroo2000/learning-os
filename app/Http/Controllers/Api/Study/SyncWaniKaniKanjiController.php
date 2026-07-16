<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Japanese\Actions\SyncWaniKaniKanjiAction;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncWaniKaniKanjiController extends Controller
{
    public function __invoke(Request $request, SyncWaniKaniKanjiAction $sync): JsonResponse
    {
        return response()->json($sync->handle(AuthenticatedUser::id($request)));
    }
}
