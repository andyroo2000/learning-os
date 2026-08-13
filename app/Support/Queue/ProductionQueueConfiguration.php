<?php

namespace App\Support\Queue;

use LogicException;

final class ProductionQueueConfiguration
{
    /** @var list<string> */
    private const DURABLE_DRIVERS = ['database', 'redis', 'sqs', 'beanstalkd'];

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

        if (in_array($driver, self::DURABLE_DRIVERS, true)) {
            return;
        }

        throw new LogicException(
            "Production queue connection [{$connection}] uses non-durable driver [{$driver}]. "
            .'Configure a durable asynchronous queue and a separately managed worker.',
        );
    }
}
