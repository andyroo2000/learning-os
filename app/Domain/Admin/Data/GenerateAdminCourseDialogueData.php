<?php

namespace App\Domain\Admin\Data;

use InvalidArgumentException;

final readonly class GenerateAdminCourseDialogueData
{
    private const MAX_PROMPT_LENGTH = 100_000;

    private function __construct(public ?string $customPrompt) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $prompt = $input['customPrompt'] ?? null;
        if ($prompt !== null && ! is_string($prompt)) {
            throw new InvalidArgumentException('Custom prompt must be a string or null.');
        }

        $prompt = is_string($prompt) ? $prompt : null;
        if ($prompt === '') {
            $prompt = null;
        }
        if ($prompt !== null && mb_strlen($prompt) > self::MAX_PROMPT_LENGTH) {
            throw new InvalidArgumentException('Custom prompt is too long.');
        }

        return new self($prompt);
    }
}
