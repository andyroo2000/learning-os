<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Calendar\Actions\ListReadableGoogleCalendarsAction;
use App\Domain\Calendar\Exceptions\GoogleCalendarProviderException;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use App\Http\Support\GoogleCalendarApiErrors;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ListReadableGoogleCalendarsController extends Controller
{
    public function __invoke(Request $request, ListReadableGoogleCalendarsAction $list): JsonResponse
    {
        try {
            return response()->json($list->handle(AuthenticatedUser::id($request)));
        } catch (ModelNotFoundException|GoogleCalendarProviderException $exception) {
            return GoogleCalendarApiErrors::response($exception);
        }
    }
}
