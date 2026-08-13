<?php

namespace Tests\Unit\Support;

use App\Support\Queue\ProductionQueueConfiguration;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductionQueueConfigurationTest extends TestCase
{
    #[DataProvider('nonDurableDriverProvider')]
    public function test_it_rejects_non_durable_drivers_in_production(string $driver): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            "Production queue connection [immediate] uses non-durable driver [{$driver}].",
        );

        ProductionQueueConfiguration::assertSafe(
            environment: 'production',
            connection: 'immediate',
            driver: $driver,
        );
    }

    /** @return array<string, array{string}> */
    public static function nonDurableDriverProvider(): array
    {
        return [
            'sync' => ['sync'],
            'deferred' => ['deferred'],
            'background' => ['background'],
            'failover' => ['failover'],
            'custom' => ['custom'],
        ];
    }

    public function test_it_rejects_an_unconfigured_connection_in_production(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Production queue connection [missing] is not configured.',
        );

        ProductionQueueConfiguration::assertSafe(
            environment: 'production',
            connection: 'missing',
            driver: null,
        );
    }

    #[DataProvider('safeConfigurationProvider')]
    public function test_it_allows_non_production_or_asynchronous_configurations(
        string $environment,
        string $connection,
        ?string $driver,
    ): void {
        ProductionQueueConfiguration::assertSafe($environment, $connection, $driver);

        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string, string, string|null}> */
    public static function safeConfigurationProvider(): array
    {
        return [
            'production database' => ['production', 'database', 'database'],
            'production redis' => ['production', 'redis', 'redis'],
            'production SQS' => ['production', 'sqs', 'sqs'],
            'production Beanstalkd' => ['production', 'beanstalkd', 'beanstalkd'],
            'local sync' => ['local', 'sync', 'sync'],
            'testing sync' => ['testing', 'sync', 'sync'],
            'unknown local driver' => ['local', 'custom', null],
        ];
    }
}
