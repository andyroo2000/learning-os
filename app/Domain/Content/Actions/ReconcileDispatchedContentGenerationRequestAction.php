<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentDialogueGenerationJob;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Domain\Content\Support\ContentGenerationRequestTerminalState;
use App\Domain\Content\Support\ContentSourceLock;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ReconcileDispatchedContentGenerationRequestAction
{
    /** @return Collection<int, string> */
    public function candidateIds(int $limit): Collection
    {
        // Keep these bulk candidate predicates aligned with the locked invariants in
        // reconcileDialogue() and reconcileCourse(). handle() always rechecks them.
        return ContentGenerationRequest::query()
            ->whereIn('state', [
                ContentGenerationRequestState::PENDING,
                ContentGenerationRequestState::ACTIVE,
            ])
            ->whereNotNull('dispatched_at')
            ->where(fn ($candidates) => $this->whereRecoverableCandidates($candidates))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
    }

    private function whereRecoverableCandidates(EloquentBuilder $candidates): void
    {
        $candidates
            ->where(fn ($dialogue) => $this->whereRecoverableDialogue($dialogue))
            ->orWhere(fn ($course) => $this->whereRecoverableCourse($course));
    }

    private function whereRecoverableDialogue(EloquentBuilder $dialogue): void
    {
        $this->whereRecoverableOperation(
            $dialogue,
            ContentGenerationRequestState::DIALOGUE_OPERATION,
            function (QueryBuilder $jobs): void {
                $jobs->selectRaw('1')
                    ->from('content_dialogue_generation_jobs')
                    ->whereColumn(
                        'content_dialogue_generation_jobs.id',
                        'content_generation_requests.job_id',
                    );
            },
            fn (QueryBuilder $jobs) => $this->whereMismatchedDialogueJob($jobs),
        );
    }

    private function whereMismatchedDialogueJob(QueryBuilder $jobs): void
    {
        $jobs->selectRaw('1')
            ->from('content_dialogue_generation_jobs')
            ->leftJoin(
                'content_episodes',
                'content_episodes.id',
                '=',
                'content_dialogue_generation_jobs.episode_id',
            )
            ->whereColumn(
                'content_dialogue_generation_jobs.id',
                'content_generation_requests.job_id',
            )
            ->where(function ($mismatch): void {
                $mismatch->whereIn('content_dialogue_generation_jobs.state', [
                    ContentDialogueGeneration::STATE_COMPLETED,
                    ContentDialogueGeneration::STATE_FAILED,
                ])
                    ->orWhereNull('content_episodes.id')
                    ->orWhereColumn(
                        'content_dialogue_generation_jobs.attempt',
                        '<>',
                        'content_generation_requests.job_attempt',
                    )
                    ->orWhereColumn(
                        'content_dialogue_generation_jobs.episode_id',
                        '<>',
                        'content_generation_requests.resource_id',
                    )
                    ->orWhereColumn(
                        'content_episodes.dialogue_generation_attempt',
                        '<>',
                        'content_generation_requests.job_attempt',
                    );
            });
    }

    private function whereRecoverableCourse(EloquentBuilder $course): void
    {
        $this->whereRecoverableOperation(
            $course,
            ContentGenerationRequestState::COURSE_OPERATION,
            function (QueryBuilder $courses): void {
                $courses->selectRaw('1')
                    ->from('content_courses')
                    ->whereColumn('content_courses.id', 'content_generation_requests.resource_id');
            },
            fn (QueryBuilder $courses) => $this->whereMismatchedCourse($courses),
        );
    }

    private function whereRecoverableOperation(
        EloquentBuilder $candidates,
        string $operation,
        Closure $missingResource,
        Closure $mismatchedResource,
    ): void {
        $candidates
            ->where('operation', $operation)
            ->where(function (EloquentBuilder $recoverable) use ($missingResource, $mismatchedResource): void {
                $recoverable
                    ->whereNotExists($missingResource)
                    ->orWhereExists($mismatchedResource);
            });
    }

    private function whereMismatchedCourse(QueryBuilder $courses): void
    {
        $courses->selectRaw('1')
            ->from('content_courses')
            ->whereColumn('content_courses.id', 'content_generation_requests.resource_id')
            ->where(function ($mismatch): void {
                $mismatch->where('content_courses.status', '<>', 'generating')
                    ->orWhereColumn(
                        'content_courses.generation_attempt',
                        '<>',
                        'content_generation_requests.job_attempt',
                    )
                    ->orWhereColumn(
                        'content_courses.id',
                        '<>',
                        'content_generation_requests.job_id',
                    );
            });
    }

    public function handle(string $requestId): bool
    {
        return DB::transaction(function () use ($requestId): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $request = ContentGenerationRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if (! $this->canReconcile($request)) {
                return false;
            }

            return match ($request->operation) {
                ContentGenerationRequestState::DIALOGUE_OPERATION => $this->reconcileDialogue($request),
                ContentGenerationRequestState::COURSE_OPERATION => $this->reconcileCourse($request),
                default => false,
            };
        });
    }

    private function canReconcile(?ContentGenerationRequest $request): bool
    {
        return $request instanceof ContentGenerationRequest
            && $request->dispatched_at !== null
            && ! ContentGenerationRequestState::isTerminal($request->state);
    }

    private function reconcileDialogue(ContentGenerationRequest $request): bool
    {
        $job = is_string($request->job_id)
            ? ContentDialogueGenerationJob::query()->whereKey($request->job_id)->lockForUpdate()->first()
            : null;
        if (! $job instanceof ContentDialogueGenerationJob) {
            return $this->fail(
                $request,
                'generation_resource_missing',
                'Dialogue generation is no longer available.',
            );
        }

        $episode = ContentEpisode::query()->whereKey($request->resource_id)->lockForUpdate()->first();
        if (! $episode instanceof ContentEpisode) {
            return $this->fail(
                $request,
                'generation_resource_missing',
                'Dialogue generation is no longer available.',
            );
        }
        if ($this->dialogueWasSuperseded($request, $job, $episode)) {
            return $this->fail(
                $request,
                'generation_superseded',
                'Dialogue generation was superseded by a newer attempt.',
            );
        }

        if ($job->state === ContentDialogueGeneration::STATE_COMPLETED) {
            return $this->complete($request);
        }
        if ($job->state === ContentDialogueGeneration::STATE_FAILED) {
            return $this->fail(
                $request,
                'generation_failed',
                (string) ($job->error_message ?: ContentDialogueGeneration::FAILED_MESSAGE),
            );
        }

        return false;
    }

    private function dialogueWasSuperseded(
        ContentGenerationRequest $request,
        ContentDialogueGenerationJob $job,
        ContentEpisode $episode,
    ): bool {
        return ! is_int($request->job_attempt)
            || (int) $job->attempt !== $request->job_attempt
            || ! hash_equals((string) $job->episode_id, (string) $request->resource_id)
            || (int) $episode->dialogue_generation_attempt !== $request->job_attempt;
    }

    private function reconcileCourse(ContentGenerationRequest $request): bool
    {
        $course = ContentCourse::query()->whereKey($request->resource_id)->lockForUpdate()->first();
        if (! $course instanceof ContentCourse) {
            return $this->fail(
                $request,
                'generation_resource_missing',
                'Course generation is no longer available.',
            );
        }
        if ($this->courseWasSuperseded($request, $course)) {
            return $this->fail(
                $request,
                'generation_superseded',
                'Course generation was superseded by a newer attempt.',
            );
        }

        return match ($course->status) {
            'ready' => $this->complete($request),
            'error' => $this->fail(
                $request,
                'generation_failed',
                (string) ($course->generation_error_message ?: 'Course generation failed. Please try again.'),
            ),
            'generating' => false,
            default => $this->fail(
                $request,
                'generation_no_longer_active',
                'Course generation is no longer active.',
            ),
        };
    }

    private function courseWasSuperseded(
        ContentGenerationRequest $request,
        ContentCourse $course,
    ): bool {
        return ! is_int($request->job_attempt)
            || ! is_string($request->job_id)
            || ! hash_equals($request->job_id, (string) $course->id)
            || (int) $course->generation_attempt !== $request->job_attempt;
    }

    private function complete(ContentGenerationRequest $request): bool
    {
        ContentGenerationRequestTerminalState::complete($request);

        return true;
    }

    private function fail(ContentGenerationRequest $request, string $code, string $message): bool
    {
        ContentGenerationRequestTerminalState::fail($request, 500, $code, $message);

        return true;
    }
}
