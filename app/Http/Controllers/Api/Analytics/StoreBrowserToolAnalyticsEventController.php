<?php

namespace App\Http\Controllers\Api\Analytics;

use App\Domain\Analytics\Actions\RecordToolAnalyticsEventAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\StoreBrowserToolAnalyticsEventRequest;
use Illuminate\Http\Response;

final class StoreBrowserToolAnalyticsEventController extends Controller
{
    public function __invoke(
        StoreBrowserToolAnalyticsEventRequest $request,
        RecordToolAnalyticsEventAction $recordEvent,
    ): Response {
        $recordEvent->handle($request->validated());

        return response()->noContent();
    }
}
