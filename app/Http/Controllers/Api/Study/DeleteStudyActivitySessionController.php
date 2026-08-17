<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\DeleteStudyActivitySessionAction;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DeleteStudyActivitySessionController extends Controller
{
    public function __invoke(
        Request $request,
        string $clientSessionId,
        DeleteStudyActivitySessionAction $deleteStudyActivitySession,
    ): JsonResponse {
        if (! $deleteStudyActivitySession->handle(AuthenticatedUser::id($request), $clientSessionId)) {
            return response()->json([
                'message' => 'Automatically recorded or provider-managed study activity cannot be deleted.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
