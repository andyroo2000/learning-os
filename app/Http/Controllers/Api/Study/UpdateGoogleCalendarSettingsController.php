<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Calendar\Actions\UpdateGoogleCalendarSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\UpdateGoogleCalendarSettingsRequest;
use App\Http\Support\AuthenticatedUser;
use App\Http\Support\GoogleCalendarApiErrors;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

final class UpdateGoogleCalendarSettingsController extends Controller
{
    public function __invoke(UpdateGoogleCalendarSettingsRequest $request, UpdateGoogleCalendarSettingsAction $update): JsonResponse
    {
        try {
            return response()->json($update->handle(AuthenticatedUser::id($request), $request->settings())->toArray());
        } catch (ModelNotFoundException $exception) {
            return GoogleCalendarApiErrors::response($exception);
        }
    }
}
