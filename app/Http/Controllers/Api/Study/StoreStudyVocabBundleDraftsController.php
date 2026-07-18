<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\CreateStudyVocabBundleDraftsAction;
use App\Domain\Study\Actions\FailStudyVocabBundleDraftsAction;
use App\Domain\Study\Data\CreateStudyVocabBundleData;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StoreStudyVocabBundleDraftsRequest;
use App\Http\Resources\Study\StudyCardDraftResource;
use App\Http\Support\AuthenticatedUser;
use App\Jobs\ProcessStudyVocabBundleDrafts;
use Illuminate\Http\JsonResponse;
use Throwable;

class StoreStudyVocabBundleDraftsController extends Controller
{
    public function __invoke(
        StoreStudyVocabBundleDraftsRequest $request,
        CreateStudyVocabBundleDraftsAction $createStudyVocabBundleDrafts,
        FailStudyVocabBundleDraftsAction $failStudyVocabBundleDrafts,
    ): JsonResponse {
        try {
            $result = $createStudyVocabBundleDrafts->handle(
                CreateStudyVocabBundleData::fromInput(
                    userId: AuthenticatedUser::id($request),
                    targetWord: $request->targetWord(),
                    sourceSentence: $request->sourceSentence(),
                    context: $request->context(),
                    includeLearnerContext: $request->includeLearnerContext(),
                ),
            );
        } catch (StudyCardDraftConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        try {
            ProcessStudyVocabBundleDrafts::dispatch($result->group->id);
        } catch (Throwable $exception) {
            report($exception);
            $failStudyVocabBundleDrafts->handle(
                $result->group->id,
                ProcessStudyVocabBundleDrafts::EXHAUSTED_ERROR_MESSAGE,
            );
            $result->drafts->each->refresh();
        }

        return response()->json([
            'groupId' => $result->group->id,
            'drafts' => $result->drafts
                ->map(static fn ($draft): array => StudyCardDraftResource::make($draft)->resolve($request))
                ->values()
                ->all(),
        ], 201);
    }
}
