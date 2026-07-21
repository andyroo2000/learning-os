<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Models\ContentAudioScriptMedia;
use App\Domain\Content\Support\ContentAudioScriptMediaPath;
use App\Domain\Content\Support\ConvoLabUserId;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\DownloadContentAudioScriptMediaRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DownloadContentAudioScriptMediaController extends Controller
{
    public function __invoke(
        DownloadContentAudioScriptMediaRequest $request,
        string $mediaId,
    ): StreamedResponse {
        $media = ContentAudioScriptMedia::query()
            ->whereKey(strtolower(trim($mediaId)))
            ->where('user_id', AuthenticatedUser::id($request))
            ->whereHas('segments.script.episode', fn ($query) => $query
                ->where('user_id', AuthenticatedUser::id($request))
                ->where('convolab_user_id', ConvoLabUserId::normalize($request->convoLabUserId()))
                ->where('content_type', 'script'))
            ->first();
        if ($media === null || ! ContentAudioScriptMediaPath::isSafe($media->storage_path)) {
            throw new NotFoundHttpException;
        }

        $contentType = strtolower(trim((string) $media->content_type));
        if (! in_array($contentType, ['image/gif', 'image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk('media');
        if (! $disk->exists($media->storage_path)) {
            throw new NotFoundHttpException;
        }

        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($media->source_filename)) ?: 'script-image';

        return $disk->response($media->storage_path, $filename, [
            'Cache-Control' => 'private, max-age=15552000, immutable',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Content-Type' => $contentType,
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
