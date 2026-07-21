<?php

namespace App\Domain\Content\Data;

use InvalidArgumentException;

final readonly class UpdateContentCourseData
{
    private function __construct(
        public bool $hasTitle,
        public ?string $title,
        public bool $hasDescription,
        public ?string $description,
        public bool $hasMaxLessonDurationMinutes,
        public ?int $maxLessonDurationMinutes,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $hasTitle = array_key_exists('title', $input);
        $hasDescription = array_key_exists('description', $input);
        $hasDuration = array_key_exists('maxLessonDurationMinutes', $input);

        $title = null;
        if ($hasTitle) {
            if (! is_string($input['title'])) {
                throw new InvalidArgumentException('Course title must be a string.');
            }
            $title = trim($input['title']);
            if ($title === '' || mb_strlen($title) > 255) {
                throw new InvalidArgumentException('Course title must contain between 1 and 255 characters.');
            }
        }

        $description = null;
        if ($hasDescription) {
            if ($input['description'] !== null && ! is_string($input['description'])) {
                throw new InvalidArgumentException('Course description must be a string or null.');
            }
            if (is_string($input['description'])) {
                $description = trim($input['description']);
                $description = $description === '' ? null : $description;
            }
        }

        $duration = null;
        if ($hasDuration) {
            $duration = filter_var($input['maxLessonDurationMinutes'], FILTER_VALIDATE_INT);
            if ($duration === false) {
                throw new InvalidArgumentException('Course duration must be an integer.');
            }
            if ($duration < 1 || $duration > 120) {
                throw new InvalidArgumentException('Course duration must be between 1 and 120 minutes.');
            }
        }

        return new self(
            hasTitle: $hasTitle,
            title: $title,
            hasDescription: $hasDescription,
            description: $description,
            hasMaxLessonDurationMinutes: $hasDuration,
            maxLessonDurationMinutes: $duration,
        );
    }
}
