<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\CreateContentCourseAction;
use App\Domain\Content\Data\CreateContentCourseData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\StoreContentCourseRequest;
use App\Http\Resources\Content\ContentCourseResource;
use Illuminate\Http\JsonResponse;

final class StoreContentCourseController extends Controller
{
    public function __invoke(
        StoreContentCourseRequest $request,
        CreateContentCourseAction $action,
    ): JsonResponse {
        $result = $action->handle(CreateContentCourseData::fromInput(
            $request->contentUserId(),
            $request->convoLabUserId(),
            $request->validated(),
        ));

        if (! $result->episodesFound) {
            return response()->json(['message' => 'One or more episodes not found'], 404);
        }

        return response()->json(
            (new ContentCourseResource($result->course))->resolve($request),
        );
    }
}
