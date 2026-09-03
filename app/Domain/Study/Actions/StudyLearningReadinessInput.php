<?php

namespace App\Domain\Study\Actions;

use Illuminate\Support\Carbon;

class StudyLearningReadinessInput
{
    /**
     * @param  array{user_id: int, course_id: string|null, deck_id: string|null, now: Carbon}  $scope
     * @param  array{due_count: int, apprentice_count: int, lesson_batch_size: int, review_time_budget_minutes: int}  $metrics
     */
    public function __construct(
        private readonly array $scope,
        private readonly array $metrics,
    ) {}

    public function userId(): int
    {
        return $this->scope['user_id'];
    }

    public function courseId(): ?string
    {
        return $this->scope['course_id'];
    }

    public function deckId(): ?string
    {
        return $this->scope['deck_id'];
    }

    public function now(): Carbon
    {
        return $this->scope['now'];
    }

    public function dueCount(): int
    {
        return $this->metrics['due_count'];
    }

    public function apprenticeCount(): int
    {
        return $this->metrics['apprentice_count'];
    }

    public function lessonBatchSize(): int
    {
        return $this->metrics['lesson_batch_size'];
    }

    public function reviewTimeBudgetMinutes(): int
    {
        return $this->metrics['review_time_budget_minutes'];
    }
}
