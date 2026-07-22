<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\GetAdminPronunciationDictionaryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminReadRequest;
use App\Http\Resources\Admin\AdminPronunciationDictionaryResource;
use Illuminate\Http\JsonResponse;

class ShowAdminPronunciationDictionaryController extends Controller
{
    public function __invoke(
        ConvoLabAdminReadRequest $request,
        GetAdminPronunciationDictionaryAction $action,
    ): JsonResponse {
        return response()->json(
            AdminPronunciationDictionaryResource::make($action->handle())->resolve($request),
        );
    }
}
