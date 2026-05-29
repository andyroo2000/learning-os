<?php

namespace App\Domain\Media\Exceptions;

use RuntimeException;
use Throwable;

final class MediaAssetValidationException extends RuntimeException
{
    public function __construct(
        private readonly string $field,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function field(): string
    {
        return $this->field;
    }
}
