<?php

namespace App\Console\Commands;

use App\Domain\Content\Actions\QueueIdempotentContentCourseGenerationAction;
use App\Domain\Content\Actions\QueueIdempotentContentDialogueGenerationAction;
use App\Domain\Content\Data\GenerateContentDialogueData;
use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Support\ContentGenerationRequestState;
use Illuminate\Console\Command;
use Throwable;

final class RecoverContentGenerationRequests extends Command
{
    protected $signature = 'content:recover-generation-requests {--limit=100}';

    protected $description = 'Resume generation requests left before job linkage or queue dispatch';

    public function handle(
        QueueIdempotentContentDialogueGenerationAction $queueDialogue,
        QueueIdempotentContentCourseGenerationAction $queueCourse,
    ): int {
        $limit = max(1, min(1_000, (int) $this->option('limit')));
        $requests = ContentGenerationRequest::query()
            ->whereIn('state', [
                ContentGenerationRequestState::PENDING,
                ContentGenerationRequestState::ACTIVE,
            ])
            ->where(function ($query): void {
                $query->where(function ($unlinked): void {
                    $unlinked->where('state', ContentGenerationRequestState::PENDING)
                        ->whereNull('job_id');
                })->orWhere(function ($linked): void {
                    $linked->whereNotNull('job_id')
                        ->whereNull('dispatched_at')
                        ->where(function ($claim): void {
                            $claim->whereNull('dispatch_claimed_at')
                                ->orWhere('dispatch_claimed_at', '<=', now()->subSeconds(
                                    ContentGenerationRequestState::DISPATCH_CLAIM_STALE_SECONDS,
                                ));
                        });
                });
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $dispatched = 0;
        foreach ($requests as $request) {
            try {
                match ($request->operation) {
                    ContentGenerationRequestState::DIALOGUE_OPERATION => $queueDialogue->handle(
                        (int) $request->user_id,
                        (string) $request->convolab_user_id,
                        (string) $request->client_request_id,
                        GenerateContentDialogueData::fromInput($request->input_payload),
                    ),
                    ContentGenerationRequestState::COURSE_OPERATION => $queueCourse->handle(
                        (int) $request->user_id,
                        (string) $request->convolab_user_id,
                        (string) $request->client_request_id,
                        (string) $request->resource_id,
                    ),
                    default => throw new \LogicException('Unsupported generation request operation.'),
                };
                if ($request->fresh()?->dispatched_at !== null) {
                    $dispatched++;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->info("Recovered {$dispatched} generation request(s).");

        return self::SUCCESS;
    }
}
