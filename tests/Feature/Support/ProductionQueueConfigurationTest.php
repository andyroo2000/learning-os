<?php

namespace Tests\Feature\Support;

use App\Providers\AppServiceProvider;
use LogicException;
use Tests\TestCase;

class ProductionQueueConfigurationTest extends TestCase
{
    public function test_application_provider_rejects_a_sync_driver_in_production(): void
    {
        $environment = $this->app->environment();
        $connection = config('queue.default');

        try {
            $this->app->detectEnvironment(fn (): string => 'production');
            config(['queue.default' => 'sync']);

            $this->expectException(LogicException::class);
            $this->expectExceptionMessage(
                'Production queue connection [sync] uses non-durable driver [sync].',
            );

            // Deliberately call boot() again; this configuration guard throws before later provider setup runs.
            (new AppServiceProvider($this->app))->boot();
        } finally {
            $this->app->detectEnvironment(fn (): string => $environment);
            config(['queue.default' => $connection]);
        }
    }
}
