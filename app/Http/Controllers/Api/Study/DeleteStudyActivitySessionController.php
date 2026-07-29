<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Models\StudyActivitySession;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DeleteStudyActivitySessionController extends Controller
{
    public function __invoke(Request $request, string $clientSessionId): JsonResponse
    {
        $session = StudyActivitySession::query()
            ->where('user_id', AuthenticatedUser::id($request))
            ->where('client_session_id', $clientSessionId)
            ->first();

        if ($session === null) {
            return response()->json(null, Response::HTTP_NO_CONTENT);
        }

        if ($session->source === StudyActivitySource::Automatic) {
            return response()->json([
                'message' => 'Automatically recorded study activity cannot be deleted.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $session->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
