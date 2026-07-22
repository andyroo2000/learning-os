<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\UploadAdminUserAvatarAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadAdminUserAvatarRequest;
use Illuminate\Http\JsonResponse;

final class UploadAdminUserAvatarController extends Controller
{
    public function __invoke(
        UploadAdminUserAvatarRequest $request,
        string $convoLabUserId,
        UploadAdminUserAvatarAction $action,
    ): JsonResponse {
        $avatarUrl = $action->handle($convoLabUserId, $request->imageBytes(), $request->cropArea());

        return response()->json([
            'message' => 'User avatar uploaded successfully',
            'avatarUrl' => $avatarUrl,
        ]);
    }
}
