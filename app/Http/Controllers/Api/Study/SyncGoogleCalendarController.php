<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Calendar\Actions\QueueManualGoogleCalendarSyncAction;
use App\Domain\Calendar\Actions\ShowGoogleCalendarConnectionAction;
use App\Domain\Calendar\Exceptions\GoogleCalendarManualSyncException;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use App\Http\Support\GoogleCalendarApiErrors;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SyncGoogleCalendarController extends Controller
{
    public function __invoke(
        Request $request,
        QueueManualGoogleCalendarSyncAction $queue,
        ShowGoogleCalendarConnectionAction $show,
    ): JsonResponse {
        try {
            $connection = $queue->handle(AuthenticatedUser::id($request));
        } catch (ModelNotFoundException $e) {
            return GoogleCalendarApiErrors::response($e);
        } catch (GoogleCalendarManualSyncException $e) {
            return GoogleCalendarApiErrors::manualSync($e);
        }

        return response()->json($show->connection($connection), 202);
    }
}
