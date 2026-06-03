<?php

namespace App\Domain\Sync\Enums;

enum SyncFeedOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $operation): string => $operation->value,
            self::cases(),
        );
    }
}
