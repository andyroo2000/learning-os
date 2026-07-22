<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\UploadAdminSpeakerAvatarAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadAdminSpeakerAvatarRequest;
use Illuminate\Http\JsonResponse;

final class UploadAdminSpeakerAvatarController extends Controller
{
    public function __invoke(
        UploadAdminSpeakerAvatarRequest $request,
        UploadAdminSpeakerAvatarAction $action,
    ): JsonResponse {
        $avatar = $action->handle($request->filename(), $request->imageBytes(), $request->cropArea());

        return response()->json([
            'message' => 'Speaker avatar uploaded successfully',
            'filename' => $avatar->filename,
            'croppedUrl' => $avatar->cropped_url,
            'originalUrl' => $avatar->original_url,
        ]);
    }
}
