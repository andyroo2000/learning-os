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

        return new self(
            hasTitle: $hasTitle,
            title: self::title($input, $hasTitle),
            hasStatus: $hasStatus,
            status: self::status($input, $hasStatus),
        );
    }

    /** @param array<string, mixed> $input */
    private static function title(array $input, bool $isPresent): ?string
    {
        if (! $isPresent) {
            return null;
        }

        $title = $input['title'];
        if (! is_string($title)) {
            throw new InvalidArgumentException('Episode title must contain at most 255 characters.');
        }

        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('Episode title must contain at most 255 characters.');
        }
        if (mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Episode title must contain at most 255 characters.');
        }

        return $title;
    }

    /** @param array<string, mixed> $input */
    private static function status(array $input, bool $isPresent): ?string
    {
        if (! $isPresent) {
            return null;
        }

        $status = $input['status'];
        if (! is_string($status)) {
            throw new InvalidArgumentException('Episode status is invalid.');
        }

        $status = trim($status);
        if (! in_array($status, ContentEpisodeInput::STATUSES, true)) {
            throw new InvalidArgumentException('Episode status is invalid.');
        }

        return $status;
    }
}
