<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DownloadDailyAudioPracticeTrackController extends Controller
{
    public function __invoke(
        Request $request,
        string $practiceId,
        string $trackId,
    ): StreamedResponse {
        $track = DailyAudioPracticeTrack::query()
            ->whereKey(strtolower($trackId))
            ->where('practice_id', strtolower($practiceId))
            ->where('status', 'ready')
            ->whereHas(
                'practice',
                fn ($query) => $query->where('user_id', AuthenticatedUser::id($request)),
            )
            ->first();

        if ($track === null) {
            throw new NotFoundHttpException;
        }

        $path = DailyAudioPracticeGeneration::storagePath($track->practice_id, $track->id);
        $disk = Storage::disk((string) config('daily_audio.disk'));
        if (! $disk->exists($path)) {
            throw new NotFoundHttpException;
        }

        return $disk->response(
            $path,
            "daily-audio-{$track->mode}.mp3",
            ['Content-Type' => 'audio/mpeg'],
        );
    }
}
