<?php

namespace App\Domain\Content\Exceptions;

use RuntimeException;

final class ContentAudioGenerationConflictException extends RuntimeException
{
    public static function differentRequestInProgress(): self
    {
        return new self('Different audio generation is already in progress');
    }
}
