<?php

namespace App\Support\Queue;

use LogicException;

final class ProductionQueueConfiguration
{
    private function __construct() {}

    public static function assertSafe(
        string $environment,
        string $connection,
        ?string $driver,
    ): void {
        if ($environment !== 'production') {
            return;
        }

        if ($driver === null) {
            throw new LogicException(
                "Production queue connection [{$connection}] is not configured.",
            );
        }

        if ($driver !== 'sync') {
            return;
        }

        throw new LogicException(
            "Production queue connection [{$connection}] uses the synchronous driver. "
            .'Configure a durable asynchronous queue and a separately managed worker.',
        );
    }
}
