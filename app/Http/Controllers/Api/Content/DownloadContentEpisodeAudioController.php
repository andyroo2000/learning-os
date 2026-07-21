<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentEpisodeAudio;
use App\Domain\Content\Support\ConvoLabUserId;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\DownloadContentEpisodeAudioRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DownloadContentEpisodeAudioController extends Controller
{
    public function __invoke(
        DownloadContentEpisodeAudioRequest $request,
        string $episodeId,
        string $track,
    ): StreamedResponse {
        $pathField = match ($track) {
            ContentEpisodeAudio::TRACK_DEFAULT => 'audio_storage_path',
            ContentEpisodeAudio::TRACK_SLOW => 'audio_storage_path_0_7',
            ContentEpisodeAudio::TRACK_MEDIUM => 'audio_storage_path_0_85',
            ContentEpisodeAudio::TRACK_NORMAL => 'audio_storage_path_1_0',
            default => throw new NotFoundHttpException,
        };
        $episode = ContentEpisode::query()
            ->whereKey(strtolower(trim($episodeId)))
            ->where('user_id', $request->user()->getKey())
            ->where('convolab_user_id', ConvoLabUserId::normalize($request->convoLabUserId()))
            ->whereNotNull($pathField)
            ->first();
        $path = $episode?->{$pathField};
        if ($episode === null || ! ContentEpisodeAudio::ownsPath($episode->id, $path)) {
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk((string) config('content_audio.disk'));
        if (! $disk->exists($path)) {
            throw new NotFoundHttpException;
        }

        return $disk->response($path, "episode-{$episode->id}-{$track}.mp3", ['Content-Type' => 'audio/mpeg']);
    }
}
