<?php

namespace App\Domain\Content\Exceptions;

use RuntimeException;

final class ContentDialogueGenerationConflictException extends RuntimeException implements ContentGenerationRejectedException
{
    public static function alreadyGenerating(): self
    {
        return new self('Dialogue is already being generated');
    }
}
