<?php

namespace App\Http\Controllers\Api\Analytics;

use App\Domain\Analytics\Actions\RecordToolAnalyticsEventAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\StoreToolAnalyticsEventRequest;
use Illuminate\Http\Response;

final class StoreToolAnalyticsEventController extends Controller
{
    public function __invoke(
        StoreToolAnalyticsEventRequest $request,
        RecordToolAnalyticsEventAction $recordEvent,
    ): Response {
        $recordEvent->handle($request->validated());

        return response()->noContent();
    }
}
