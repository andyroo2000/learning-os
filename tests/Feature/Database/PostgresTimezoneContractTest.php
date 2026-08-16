<?php

namespace Tests\Feature\Database;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresTimezoneContractTest extends TestCase
{
    public function test_postgres_sessions_and_timestamp_bindings_use_the_utc_application_contract(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required to exercise its session timezone contract.');
        }

        $this->assertSame('UTC', config('database.connections.pgsql.timezone'));
        $timezone = (array) DB::selectOne('show timezone');
        $this->assertSame('UTC', array_values($timezone)[0]);

        DB::statement('create temporary table postgres_timezone_contract (occurred_at timestamptz not null)');
        $instant = CarbonImmutable::parse('2026-08-15T09:30:00Z');
        DB::table('postgres_timezone_contract')->insert(['occurred_at' => $instant]);
        $storedEpoch = DB::table('postgres_timezone_contract')
            ->selectRaw('extract(epoch from occurred_at)::bigint as epoch')
            ->value('epoch');

        $this->assertSame($instant->getTimestamp(), (int) $storedEpoch);
    }
}
