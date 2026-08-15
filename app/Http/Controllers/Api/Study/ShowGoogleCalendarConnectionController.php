<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Calendar\Actions\ShowGoogleCalendarConnectionAction;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowGoogleCalendarConnectionController extends Controller
{
    public function __invoke(Request $request, ShowGoogleCalendarConnectionAction $show): JsonResponse
    {
        return response()->json($show->handle(AuthenticatedUser::id($request)));
    }
}
