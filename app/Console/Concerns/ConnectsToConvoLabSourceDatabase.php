<?php

namespace App\Console\Concerns;

use App\Console\Support\ConvoLabSourceDatabaseName;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait ConnectsToConvoLabSourceDatabase
{
    protected function convoLabSourceConnection(): ConnectionInterface
    {
        $database = $this->convoLabSourceDatabaseName();
        $connectionName = $this->convoLabSourceConnectionName();

        if ($database === null) {
            if (config("database.connections.{$connectionName}") === null) {
                throw new RuntimeException('Pass --source-database with the restored Convo Lab source database name.');
            }

            return DB::connection($connectionName);
        }

        if ($connectionName === DB::getDefaultConnection()) {
            throw new RuntimeException('Source connection name must differ from the target connection name.');
        }

        config(["database.connections.{$connectionName}" => $this->convoLabSourceConfig($database)]);
        DB::purge($connectionName);

        return DB::connection($connectionName);
    }

    protected function assertConvoLabSourceDiffersFromTarget(
        ConnectionInterface $source,
        ConnectionInterface $target,
    ): void {
        if ($this->connectionsResolveToSameDatabase($source, $target)) {
            throw new RuntimeException(
                'Source and target databases resolve to the same database. Use a separate restored source copy.',
            );
        }
    }

    protected function convoLabSourceMediaRoot(): string
    {
        $root = $this->option('source-media-root');

        if (! is_string($root) || trim($root) === '') {
            throw new RuntimeException('Pass --source-media-root with the exported Convo Lab media directory.');
        }

        $resolved = realpath($root);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException("Source media root [{$root}] is not a readable directory.");
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    protected function resolveConvoLabSourceFile(
        string $root,
        string $path,
        string $missingMessage,
    ): string {
        $candidate = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));

        if ($candidate === false || ! is_file($candidate)) {
            throw new RuntimeException($missingMessage);
        }

        if (! str_starts_with($candidate, $root.DIRECTORY_SEPARATOR) && $candidate !== $root) {
            throw new RuntimeException($missingMessage);
        }

        return $candidate;
    }

    private function convoLabSourceConnectionName(): string
    {
        $connectionName = trim((string) $this->option('source-connection'));

        if ($connectionName === '') {
            throw new RuntimeException('Source connection name must not be blank.');
        }

        return $connectionName;
    }

    private function convoLabSourceDatabaseName(): ?ConvoLabSourceDatabaseName
    {
        return ConvoLabSourceDatabaseName::fromOption($this->option('source-database'));
    }

    private function connectionsResolveToSameDatabase(
        ConnectionInterface $source,
        ConnectionInterface $target,
    ): bool {
        if ($source->getDatabaseName() !== $target->getDatabaseName()) {
            return false;
        }

        if ($source->getConfig('host') !== $target->getConfig('host')) {
            return false;
        }

        return (string) $source->getConfig('port') === (string) $target->getConfig('port');
    }

    /** @return array<string, mixed> */
    private function convoLabSourceConfig(ConvoLabSourceDatabaseName $database): array
    {
        $targetConfig = config('database.connections.'.DB::getDefaultConnection(), []);
        $sourceConfig = config('database.connections.pgsql');
        $sourceConfig['host'] = $this->option('source-host') ?: ($targetConfig['host'] ?? '127.0.0.1');
        $sourceConfig['port'] = $this->option('source-port') ?: ($targetConfig['port'] ?? '5432');
        $sourceConfig['database'] = $database->value;
        $sourceConfig['username'] = $this->option('source-username') ?: ($targetConfig['username'] ?? null);
        $sourceConfig['password'] = $this->option('source-password') ?? ($targetConfig['password'] ?? null);

        return $sourceConfig;
    }
}
