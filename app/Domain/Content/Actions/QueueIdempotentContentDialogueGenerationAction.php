<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\GenerateContentDialogueData;
use App\Domain\Content\Exceptions\ContentDialogueGenerationQueueException;
use App\Domain\Content\Models\ContentDialogueGenerationJob;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Results\ContentDialogueGenerationRequestResult;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Domain\Content\Support\ContentGenerationRequestFingerprint;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentDialogueGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class QueueIdempotentContentDialogueGenerationAction
{
    public function __construct(
        private readonly ReserveContentGenerationRequestAction $reserve,
        private readonly ClaimContentGenerationDispatchAction $claimDispatch,
        private readonly FinishContentGenerationDispatchAction $finishDispatch,
        private readonly FailContentDialogueGenerationAction $fail,
    ) {}

    public function handle(
        int $userId,
        string $convoLabUserId,
        ?string $clientRequestId,
        GenerateContentDialogueData $data,
    ): ?ContentDialogueGenerationRequestResult {
        $reserved = $this->reserve->handle(
            $userId,
            $convoLabUserId,
            $clientRequestId,
            ContentGenerationRequestState::DIALOGUE_OPERATION,
            'episode',
            $data->episodeId,
            ContentGenerationRequestFingerprint::dialogue($data),
            $data->toArray(),
        );
        $requestId = $reserved->request->id;

        $result = DB::transaction(function () use ($data, $requestId, $userId): ?ContentDialogueGenerationRequestResult {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $request = ContentGenerationRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
            if ($request->job_id !== null || ContentGenerationRequestState::isTerminal($request->state)) {
                return $this->dialogueResult($request);
            }

            $episode = ContentEpisode::query()
                ->whereKey($data->episodeId)
                ->where('user_id', $userId)
                ->where('convolab_user_id', $request->convolab_user_id)
                ->lockForUpdate()
                ->first();
            if (! $episode instanceof ContentEpisode) {
                $this->reject($request, 404, 'not_found', 'Episode not found');

                return new ContentDialogueGenerationRequestResult($request, null);
            }
            if ($episode->status === 'generating') {
                $this->reject($request, 400, 'already_generating', 'Dialogue is already being generated');

                return new ContentDialogueGenerationRequestResult($request, null);
            }

            $episode->source_system = ContentSourceSystem::LEARNING_OS;
            $episode->status = 'generating';
            $episode->dialogue_generation_attempt = ((int) $episode->dialogue_generation_attempt) + 1;
            $episode->save();

            $job = new ContentDialogueGenerationJob;
            $job->id = (string) Str::uuid();
            $job->episode_id = $episode->id;
            $job->user_id = $userId;
            $job->convolab_user_id = $request->convolab_user_id;
            $job->attempt = $episode->dialogue_generation_attempt;
            $job->state = ContentDialogueGeneration::STATE_WAITING;
            $job->progress = 0;
            $job->input = $data->toArray();
            $job->save();

            $request->job_id = $job->id;
            $request->job_attempt = (int) $job->attempt;
            $request->state = ContentGenerationRequestState::PENDING;
            $request->save();

            return new ContentDialogueGenerationRequestResult($request, $job);
        });

        return $this->dispatchIfNeeded($result);
    }

    private function dialogueResult(ContentGenerationRequest $request): ContentDialogueGenerationRequestResult
    {
        $job = is_string($request->job_id)
            ? ContentDialogueGenerationJob::query()->find($request->job_id)
            : null;

        return new ContentDialogueGenerationRequestResult($request, $job);
    }

    private function dispatchIfNeeded(
        ContentDialogueGenerationRequestResult $result,
    ): ContentDialogueGenerationRequestResult {
        $jobId = $result->request->job_id;
        if (! is_string($jobId) || ContentGenerationRequestState::isTerminal($result->request->state)) {
            return $result;
        }

        $dispatchToken = $this->claimDispatch->handle($result->request->id);
        if ($dispatchToken === null) {
            return $result;
        }

        try {
            ProcessContentDialogueGeneration::dispatch($jobId, $result->request->id);
            $this->finishDispatch->succeeded($result->request->id, $dispatchToken);
        } catch (Throwable $exception) {
            report($exception);
            try {
                $this->finishDispatch->failed(
                    $result->request->id,
                    $dispatchToken,
                    ContentDialogueGeneration::QUEUE_FAILED_MESSAGE,
                );
            } catch (Throwable $ledgerException) {
                report($ledgerException);
            }
            try {
                $this->fail->handle($jobId, ContentDialogueGeneration::QUEUE_FAILED_MESSAGE);
            } catch (Throwable $failureException) {
                report($failureException);
            }

            throw new ContentDialogueGenerationQueueException(
                ContentDialogueGeneration::QUEUE_FAILED_MESSAGE,
                $result->request->client_request_id,
                previous: $exception,
            );
        }

        return new ContentDialogueGenerationRequestResult(
            $result->request->fresh(),
            $result->job,
            $dispatchToken,
        );
    }

    private function reject(
        ContentGenerationRequest $request,
        int $status,
        string $code,
        string $message,
    ): void {
        $request->state = ContentGenerationRequestState::FAILED;
        $request->response_status = $status;
        $request->error_code = $code;
        $request->error_message = $message;
        $request->finished_at = now();
        $request->save();
    }
}
