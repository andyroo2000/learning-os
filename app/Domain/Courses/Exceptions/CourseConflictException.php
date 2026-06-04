<?php

namespace App\Domain\Courses\Exceptions;

use App\Domain\Courses\Models\Course;
use RuntimeException;

final class CourseConflictException extends RuntimeException
{
    private const CONFLICT_MESSAGE = 'Course ID already exists with different metadata.';

    private const DELETED_MESSAGE = 'Course ID belongs to a deleted course.';

    private const CONFLICT_REASON = 'course_id_conflict';

    private const DELETED_REASON = 'course_deleted';

    private function __construct(
        string $message,
        private readonly int $conflictingUserId,
        private readonly string $reason,
        private readonly bool $deleted = false,
    ) {
        parent::__construct($message);
    }

    public static function conflict(Course $course): self
    {
        return new self(
            message: self::CONFLICT_MESSAGE,
            conflictingUserId: $course->user_id,
            reason: self::CONFLICT_REASON,
        );
    }

    public static function deleted(Course $course): self
    {
        return new self(
            message: self::DELETED_MESSAGE,
            conflictingUserId: $course->user_id,
            reason: self::DELETED_REASON,
            deleted: true,
        );
    }

    public function shouldBeHiddenFrom(int $userId): bool
    {
        return $this->conflictingUserId !== $userId;
    }

    public function shouldBeGoneFor(int $userId): bool
    {
        return $this->deleted && ! $this->shouldBeHiddenFrom($userId);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
