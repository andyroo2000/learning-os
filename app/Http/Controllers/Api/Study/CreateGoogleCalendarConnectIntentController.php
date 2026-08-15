<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Calendar\Actions\CreateGoogleCalendarConnectIntentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\CreateGoogleCalendarConnectIntentRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class CreateGoogleCalendarConnectIntentController extends Controller
{
    public function __invoke(
        CreateGoogleCalendarConnectIntentRequest $request,
        CreateGoogleCalendarConnectIntentAction $create,
    ): JsonResponse {
        return response()->json([
            'authorizationUrl' => $create->handle(
                AuthenticatedUser::id($request),
                $request->completionTarget(),
            ),
        ]);
    }
}
