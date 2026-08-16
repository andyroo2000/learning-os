<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Calendar\Exceptions\GoogleCalendarProviderException;
use App\Domain\Calendar\Exceptions\GoogleCalendarSelectionException;
use App\Domain\Study\Actions\PreviewGoogleCalendarEventsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\PreviewGoogleCalendarRequest;
use App\Http\Support\AuthenticatedUser;
use App\Http\Support\GoogleCalendarApiErrors;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

final class PreviewGoogleCalendarController extends Controller
{
    public function __invoke(PreviewGoogleCalendarRequest $request, PreviewGoogleCalendarEventsAction $preview): JsonResponse
    {
        try {
            return response()->json($preview->handle(AuthenticatedUser::id($request), $request->criteria()));
        } catch (GoogleCalendarSelectionException) {
            return response()->json(['error' => ['code' => 'calendar_unavailable', 'message' => 'One or more selected calendars are unavailable.']], 422);
        } catch (ModelNotFoundException|GoogleCalendarProviderException $exception) {
            return GoogleCalendarApiErrors::response($exception);
        }
    }
}
