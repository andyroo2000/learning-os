<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\BuildStudyMediaBatchAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\DownloadStudyMediaBatchRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class DownloadStudyMediaBatchController extends Controller
{
    public function __invoke(
        DownloadStudyMediaBatchRequest $request,
        BuildStudyMediaBatchAction $buildStudyMediaBatch,
    ): JsonResponse {
        return response()->json([
            'items' => $buildStudyMediaBatch->handle(
                AuthenticatedUser::id($request),
                $request->ids(),
            ),
        ]);
    }
}
