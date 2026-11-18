<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\GetJournalAnalytics;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\JournalAnalyticsRequest;
use Illuminate\Http\JsonResponse;

final class ShowJournalAnalytics extends Controller
{
    public function __invoke(JournalAnalyticsRequest $request, GetJournalAnalytics $action): JsonResponse
    {
        $data = $request->validated();

        return response()->json($action->handle($data['period'] ?? 'daily', (int) ($data['page'] ?? 1)))
            ->header('Cache-Control', 'private, no-store');
    }
}
