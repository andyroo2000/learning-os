<?php

namespace App\Domain\Study\Enums;

use InvalidArgumentException;

enum StudyImportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

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

    public static function fromFilter(self|string $status): self
    {
        if ($status instanceof self) {
            return $status;
        }

        $normalized = strtolower(trim($status));

        if ($normalized === '') {
            throw new InvalidArgumentException('Study import status filter must not be blank when provided.');
        }

        return self::tryFrom($normalized)
            ?? throw new InvalidArgumentException(
                'Study import status filter must be one of: '.implode(', ', self::values()).'.',
            );
    }
}
