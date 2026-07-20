<?php

namespace App\Console\Concerns;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

trait ConnectsToConvoLabSource
{
    private function sourceConnection(): ConnectionInterface
    {
        $database = $this->option('source-database');
        $connectionName = trim((string) $this->option('source-connection'));

        if ($connectionName === '') {
            throw new \InvalidArgumentException('Source connection name must not be blank.');
        }

        if (! is_string($database) || trim($database) === '') {
            if (config("database.connections.{$connectionName}") !== null) {
                return DB::connection($connectionName);
            }

            throw new \InvalidArgumentException('Pass --source-database with the Convo Lab source database name.');
        }

        if ($connectionName === DB::getDefaultConnection()) {
            throw new \InvalidArgumentException('Source connection name must differ from the target connection name.');
        }

        $targetConfig = config('database.connections.'.DB::getDefaultConnection(), []);
        $sourceConfig = config('database.connections.pgsql');
        $sourceConfig['host'] = $this->option('source-host') ?: ($targetConfig['host'] ?? '127.0.0.1');
        $sourceConfig['port'] = $this->option('source-port') ?: ($targetConfig['port'] ?? '5432');
        $sourceConfig['database'] = trim($database);
        $sourceConfig['username'] = $this->option('source-username') ?: ($targetConfig['username'] ?? null);
        $sourceConfig['password'] = $this->option('source-password') ?? ($targetConfig['password'] ?? null);

        config(["database.connections.{$connectionName}" => $sourceConfig]);
        DB::purge($connectionName);

        return DB::connection($connectionName);
    }

    private function sameDatabase(ConnectionInterface $source, ConnectionInterface $target): bool
    {
        return $source->getDatabaseName() === $target->getDatabaseName()
            && $source->getConfig('host') === $target->getConfig('host')
            && (string) $source->getConfig('port') === (string) $target->getConfig('port');
    }
}
