<?php

namespace App\Domain\Content\Exceptions;

use RuntimeException;

final class ContentCourseGenerationConflictException extends RuntimeException implements ContentGenerationRejectedException
{
    public static function alreadyGenerating(): self
    {
        return new self('Course is already being generated');
    }

    public static function notGenerating(): self
    {
        return new self('Course is not in generating status');
    }

    public static function activeGeneration(): self
    {
        return new self('Course has an active generation job. Cannot reset.');
    }

    public static function notRetryable(): self
    {
        return new self('Only courses in error status can be retried');
    }

    public static function scriptChanged(): self
    {
        return new self('Course script changed while audio generation was being queued');
    }
}
