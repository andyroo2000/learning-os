<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\CreateDailyAudioPracticeAction;
use App\Domain\Study\Actions\FailDailyAudioPracticeAction;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StoreDailyAudioPracticeRequest;
use App\Http\Resources\Study\DailyAudioPracticeResource;
use App\Http\Support\AuthenticatedUser;
use App\Jobs\ProcessDailyAudioPractice;
use Illuminate\Http\JsonResponse;
use Throwable;

class StoreDailyAudioPracticeController extends Controller
{
    public function __invoke(
        StoreDailyAudioPracticeRequest $request,
        CreateDailyAudioPracticeAction $create,
        FailDailyAudioPracticeAction $fail,
    ): JsonResponse {
        $dispatchFailed = false;
        $practice = $create->handle(
            AuthenticatedUser::id($request),
            $request->practiceDate(),
            $request->targetDurationMinutes(),
            afterCommit: static function (string $practiceId) use (
                &$dispatchFailed,
                $fail,
            ): void {
                try {
                    ProcessDailyAudioPractice::dispatch($practiceId);
                } catch (Throwable $exception) {
                    $dispatchFailed = true;
                    report($exception);
                    $fail->handle(
                        $practiceId,
                        DailyAudioPracticeGeneration::QUEUE_FAILED_MESSAGE,
                    );
                }
            },
        );

        if ($dispatchFailed) {
            $practice->refresh()->load([
                'tracks' => fn ($query) => $query->orderBy('sort_order'),
            ]);
        }

        return response()->json(
            DailyAudioPracticeResource::make($practice)->resolve($request),
            202,
        );
    }
}
