<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\GetAdminStatsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminReadRequest;
use Illuminate\Http\JsonResponse;

class ShowAdminStatsController extends Controller
{
    public function __invoke(ConvoLabAdminReadRequest $request, GetAdminStatsAction $action): JsonResponse
    {
        return response()->json($action->handle());
    }
}
