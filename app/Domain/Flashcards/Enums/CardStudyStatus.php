<?php

namespace App\Domain\Flashcards\Enums;

use InvalidArgumentException;

enum CardStudyStatus: string
{
    case New = 'new';
    case Learning = 'learning';
    case Review = 'review';
    case Relearning = 'relearning';
    case Suspended = 'suspended';
    case Buried = 'buried';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }

    public static function fromFilter(self|string $studyStatus): self
    {
        if ($studyStatus instanceof self) {
            return $studyStatus;
        }

        $normalized = strtolower(trim($studyStatus));

        if ($normalized === '') {
            throw new InvalidArgumentException('Card study_status filter must not be blank when provided.');
        }

        return self::tryFrom($normalized)
            ?? throw new InvalidArgumentException(
                'Card study_status filter must be new, learning, review, relearning, suspended, or buried.',
            );
    }
}
