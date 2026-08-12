<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentDialogueGenerationJob;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Domain\Content\Support\ContentSourceLock;
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
            ->where(function ($candidates): void {
                $candidates->where(function ($dialogue): void {
                    $dialogue->where('operation', ContentGenerationRequestState::DIALOGUE_OPERATION)
                        ->where(function ($recoverable): void {
                            $recoverable->whereNotExists(function ($jobs): void {
                                $jobs->selectRaw('1')
                                    ->from('content_dialogue_generation_jobs')
                                    ->whereColumn(
                                        'content_dialogue_generation_jobs.id',
                                        'content_generation_requests.job_id',
                                    );
                            })->orWhereExists(function ($jobs): void {
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
                            });
                        });
                })->orWhere(function ($course): void {
                    $course->where('operation', ContentGenerationRequestState::COURSE_OPERATION)
                        ->where(function ($recoverable): void {
                            $recoverable->whereNotExists(function ($courses): void {
                                $courses->selectRaw('1')
                                    ->from('content_courses')
                                    ->whereColumn('content_courses.id', 'content_generation_requests.resource_id');
                            })->orWhereExists(function ($courses): void {
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
                            });
                        });
                });
            })
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
    }

    public function handle(string $requestId): bool
    {
        return DB::transaction(function () use ($requestId): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $request = ContentGenerationRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if (! $request instanceof ContentGenerationRequest
                || $request->dispatched_at === null
                || ContentGenerationRequestState::isTerminal($request->state)) {
                return false;
            }

            return match ($request->operation) {
                ContentGenerationRequestState::DIALOGUE_OPERATION => $this->reconcileDialogue($request),
                ContentGenerationRequestState::COURSE_OPERATION => $this->reconcileCourse($request),
                default => false,
            };
        });
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
        if (! is_int($request->job_attempt)
            || (int) $job->attempt !== $request->job_attempt
            || ! hash_equals((string) $job->episode_id, (string) $request->resource_id)
            || (int) $episode->dialogue_generation_attempt !== $request->job_attempt) {
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
        if (! is_int($request->job_attempt)
            || ! is_string($request->job_id)
            || ! hash_equals($request->job_id, (string) $course->id)
            || (int) $course->generation_attempt !== $request->job_attempt) {
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

    private function complete(ContentGenerationRequest $request): bool
    {
        $request->state = ContentGenerationRequestState::COMPLETED;
        $request->response_status = 200;
        $request->error_code = null;
        $request->error_message = null;
        $request->finished_at ??= now();
        $request->save();

        return true;
    }

    private function fail(ContentGenerationRequest $request, string $code, string $message): bool
    {
        $request->state = ContentGenerationRequestState::FAILED;
        $request->response_status = 500;
        $request->error_code = $code;
        $request->error_message = $message;
        $request->finished_at ??= now();
        $request->save();

        return true;
    }
}
