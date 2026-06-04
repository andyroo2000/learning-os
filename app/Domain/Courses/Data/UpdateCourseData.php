<?php

namespace App\Domain\Courses\Data;

use InvalidArgumentException;

final readonly class UpdateCourseData
{
    private function __construct(
        public string $title,
        public ?string $description,
    ) {}

    public static function fromInput(
        string $title,
        ?string $description,
    ): self {
        $title = trim($title);
        $description = $description === null ? null : trim($description);

        if ($title === '') {
            throw new InvalidArgumentException('Course title is required.');
        }

        return new self(
            title: $title,
            description: $description === '' ? null : $description,
        );
    }
}
