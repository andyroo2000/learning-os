<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Models\AdminScriptLabAudioRendering;
use App\Domain\Admin\Support\AdminScriptLabAudio;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminActorReadRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DownloadAdminScriptLabAudioController extends Controller
{
    public function __invoke(
        ConvoLabAdminActorReadRequest $request,
        string $renderingId,
    ): StreamedResponse {
        $actorConvoLabUserId = $request->actorConvoLabUserId();
        $rendering = AdminScriptLabAudioRendering::query()
            ->whereKey(strtolower($renderingId))
            ->where('actor_convolab_user_id', $actorConvoLabUserId)
            ->first();
        if (! $rendering instanceof AdminScriptLabAudioRendering
            || ! AdminScriptLabAudio::ownsPath(
                $actorConvoLabUserId,
                $rendering->id,
                $rendering->audio_storage_path,
            )) {
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk((string) config('content_courses.audio_disk'));
        if (! $disk->exists($rendering->audio_storage_path)) {
            throw new NotFoundHttpException;
        }

        return $disk->response(
            $rendering->audio_storage_path,
            'script-lab-line.mp3',
            ['Content-Type' => 'audio/mpeg'],
        );
    }
}
