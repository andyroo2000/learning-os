<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Japanese\Actions\ShowKnownKanjiAction;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowKnownKanjiController extends Controller
{
    public function __invoke(Request $request, ShowKnownKanjiAction $show): JsonResponse
    {
        return response()->json($show->handle(AuthenticatedUser::id($request)));
    }
}
