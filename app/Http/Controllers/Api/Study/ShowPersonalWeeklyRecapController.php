<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\BuildPersonalWeeklyRecapAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ShowPersonalWeeklyRecapRequest;
use App\Http\Support\AuthenticatedUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

final class ShowPersonalWeeklyRecapController extends Controller
{
    public function __invoke(
        ShowPersonalWeeklyRecapRequest $request,
        BuildPersonalWeeklyRecapAction $recap,
    ): JsonResponse {
        $userId = AuthenticatedUser::id($request);
        $timezone = $request->timezone();
        $weekStartsOn = $request->weekStartsOn();
        $now = CarbonImmutable::now($timezone);
        $currentWeekStart = $recap->currentWeekStart($timezone, $weekStartsOn, $now);
        $cacheKey = implode(':', [
            'study-weekly-recap-v1',
            $userId,
            $timezone->getName(),
            $weekStartsOn,
            $currentWeekStart->utc()->format('YmdHis'),
        ]);

        // Completed-week data changes only through late provider imports or manual
        // corrections. A short cache removes repeated raw-ledger scans while keeping
        // those uncommon corrections visible without cross-domain invalidation.
        $value = Cache::remember(
            $cacheKey,
            now()->addMinutes(15),
            fn (): array => $recap->handle($userId, $timezone, $weekStartsOn, $now),
        );

        return response()->json($value);
    }
}
