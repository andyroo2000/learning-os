<?php

namespace App\Domain\Flashcards\Exceptions;

use RuntimeException;

class DeckCourseNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $courseId,
    ) {
        parent::__construct('Course not found.');
    }
}
