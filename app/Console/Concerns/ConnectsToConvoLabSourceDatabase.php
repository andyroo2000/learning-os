<?php

namespace App\Console\Concerns;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait ConnectsToConvoLabSourceDatabase
{
    protected function convoLabSourceConnection(): ConnectionInterface
    {
        $database = $this->option('source-database');
        $connectionName = trim((string) $this->option('source-connection'));

        if ($connectionName === '') {
            throw new RuntimeException('Source connection name must not be blank.');
        }

        if (! is_string($database) || trim($database) === '') {
            if (config("database.connections.{$connectionName}") !== null) {
                return DB::connection($connectionName);
            }

            throw new RuntimeException('Pass --source-database with the restored Convo Lab source database name.');
        }

        if ($connectionName === DB::getDefaultConnection()) {
            throw new RuntimeException('Source connection name must differ from the target connection name.');
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

    protected function assertConvoLabSourceDiffersFromTarget(
        ConnectionInterface $source,
        ConnectionInterface $target,
    ): void {
        if ($source->getDatabaseName() === $target->getDatabaseName()
            && $source->getConfig('host') === $target->getConfig('host')
            && (string) $source->getConfig('port') === (string) $target->getConfig('port')) {
            throw new RuntimeException(
                'Source and target databases resolve to the same database. Use a separate restored source copy.',
            );
        }
    }
}
