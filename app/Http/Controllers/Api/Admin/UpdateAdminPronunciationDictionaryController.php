<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Admin\Actions\UpdateAdminPronunciationDictionaryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminPronunciationDictionaryRequest;
use App\Http\Resources\Admin\AdminPronunciationDictionaryResource;
use Illuminate\Http\JsonResponse;

class UpdateAdminPronunciationDictionaryController extends Controller
{
    public function __invoke(
        UpdateAdminPronunciationDictionaryRequest $request,
        UpdateAdminPronunciationDictionaryAction $action,
    ): JsonResponse {
        return response()->json(AdminPronunciationDictionaryResource::make(
            $action->handle($request->dictionaryData()),
        )->resolve($request));
    }
}
