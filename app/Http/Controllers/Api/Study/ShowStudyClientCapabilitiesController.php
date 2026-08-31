<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Support\StudyClientCapabilities;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyClientCapabilitiesResource;
use Illuminate\Http\JsonResponse;

final class ShowStudyClientCapabilitiesController extends Controller
{
    public function __invoke(StudyClientCapabilities $capabilities): JsonResponse
    {
        return StudyClientCapabilitiesResource::make($capabilities)->response();
    }
}
