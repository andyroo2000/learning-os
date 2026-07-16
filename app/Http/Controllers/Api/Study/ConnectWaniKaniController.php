<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Japanese\Actions\ConnectWaniKaniAction;
use App\Domain\Japanese\Actions\ShowKnownKanjiAction;
use App\Domain\Japanese\Exceptions\WaniKaniApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ConnectWaniKaniRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ConnectWaniKaniController extends Controller
{
    public function __invoke(
        ConnectWaniKaniRequest $request,
        ConnectWaniKaniAction $connect,
        ShowKnownKanjiAction $show,
    ): JsonResponse {
        try {
            $connect->handle(AuthenticatedUser::id($request), $request->apiToken());
        } catch (WaniKaniApiException $e) {
            if ($e->getCode() === 401) {
                throw ValidationException::withMessages(['apiToken' => [$e->getMessage()]]);
            }
            throw $e;
        }

        return response()->json($show->handle(AuthenticatedUser::id($request)));
    }
}
