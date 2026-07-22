<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\RecropAdminSpeakerAvatarAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecropAdminSpeakerAvatarRequest;
use Illuminate\Http\JsonResponse;

final class RecropAdminSpeakerAvatarController extends Controller
{
    public function __invoke(
        RecropAdminSpeakerAvatarRequest $request,
        RecropAdminSpeakerAvatarAction $action,
    ): JsonResponse {
        $avatar = $action->handle($request->filename(), $request->cropArea());

        return response()->json([
            'message' => 'Speaker avatar re-cropped successfully',
            'filename' => $avatar->filename,
            'croppedUrl' => $avatar->cropped_url,
            'originalUrl' => $avatar->original_url,
        ]);
    }
}
