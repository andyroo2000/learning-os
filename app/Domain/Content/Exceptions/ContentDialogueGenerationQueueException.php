<?php

namespace App\Domain\Content\Exceptions;

use RuntimeException;
use Throwable;

final class ContentDialogueGenerationQueueException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $clientRequestId,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
