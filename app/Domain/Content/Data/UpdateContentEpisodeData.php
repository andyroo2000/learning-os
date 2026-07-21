<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentEpisodeInput;
use InvalidArgumentException;

final readonly class UpdateContentEpisodeData
{
    private function __construct(
        public bool $hasTitle,
        public ?string $title,
        public bool $hasStatus,
        public ?string $status,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $hasTitle = array_key_exists('title', $input);
        $hasStatus = array_key_exists('status', $input);
        $title = $hasTitle && is_string($input['title']) ? trim($input['title']) : null;
        $status = $hasStatus && is_string($input['status']) ? trim($input['status']) : null;

        if ($hasTitle && ($title === null || $title === '' || mb_strlen($title) > 255)) {
            throw new InvalidArgumentException('Episode title must contain at most 255 characters.');
        }
        if ($hasStatus && ($status === null || ! in_array($status, ContentEpisodeInput::STATUSES, true))) {
            throw new InvalidArgumentException('Episode status is invalid.');
        }

        return new self($hasTitle, $title, $hasStatus, $status);
    }
}
