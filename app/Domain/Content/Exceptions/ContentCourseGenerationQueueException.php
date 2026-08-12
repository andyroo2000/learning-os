<?php

namespace App\Domain\Content\Exceptions;

use RuntimeException;
use Throwable;

final class ContentCourseGenerationQueueException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $clientRequestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
