<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Models\ContentAudioScriptRender;
use App\Domain\Content\Support\ContentAudioScriptRenderAudio;
use App\Domain\Content\Support\ConvoLabUserId;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\DownloadContentAudioScriptRenderRequest;
use App\Http\Support\AuthenticatedUser;
use App\Support\Audio\AudioStreamResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DownloadContentAudioScriptRenderController extends Controller
{
    public function __invoke(
        DownloadContentAudioScriptRenderRequest $request,
        AudioStreamResponse $stream,
        string $episodeId,
        string $renderId,
    ): StreamedResponse {
        $episodeId = strtolower(trim($episodeId));
        $render = ContentAudioScriptRender::query()
            ->whereKey(strtolower(trim($renderId)))
            ->whereHas('script.episode', fn ($query) => $query
                ->whereKey($episodeId)
                ->where('user_id', AuthenticatedUser::id($request))
                ->where('convolab_user_id', ConvoLabUserId::normalize($request->convoLabUserId()))
                ->where('content_type', 'script'))
            ->first();
        if ($render === null || ! ContentAudioScriptRenderAudio::ownsPath($episodeId, $render->audio_storage_path)) {
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk((string) config('content_audio.disk'));
        if (! $disk->exists($render->audio_storage_path)) {
            throw new NotFoundHttpException;
        }

        return $stream->make($request, $disk, $render->audio_storage_path, "script-{$episodeId}-{$render->speed}.mp3", [
            'Cache-Control' => 'private, max-age=15552000, immutable',
        ]);
    }
}
