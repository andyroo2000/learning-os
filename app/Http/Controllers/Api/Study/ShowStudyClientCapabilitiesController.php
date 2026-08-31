<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Support\StudyClientCapabilities;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class ShowStudyClientCapabilitiesController extends Controller
{
    public function __invoke(StudyClientCapabilities $capabilities): JsonResponse
    {
        return response()->json($capabilities->toArray());
    }
}
