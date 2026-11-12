<?php

namespace Tests\Unit\Journal;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalEntryMigrationTest extends TestCase
{
    #[DataProvider('grammars')]
    public function test_actual_migration_and_rollback_compile_for_each_database(string $connectionClass, string $grammarClass): void
    {
        // SQLite PDO is only a compile-time stand-in; no dialect-specific SQL is executed.
        $connection = new $connectionClass(new PDO('sqlite::memory:'), 'test');
        $connection->setSchemaGrammar(new $grammarClass($connection));
        Schema::swap($connection->getSchemaBuilder());
        $migration = require database_path('migrations/2026_11_12_140000_create_journal_entries.php');
        $create = $connection->pretend(fn () => $migration->up());
        $drop = $connection->pretend(fn () => $migration->down());
        $sql = strtolower(str_replace(['"', '`'], '', implode("\n", array_column($create, 'query'))));

        $this->assertStringContainsString('create table journal_entries', $sql);
        $this->assertStringContainsString('on delete cascade', $sql);
        $this->assertStringContainsString('journal_entries_archive_idx', $sql);
        $this->assertStringContainsString('(user_id, deleted_at, entry_date, id)', $sql);
        $this->assertLessThanOrEqual(63, strlen('journal_entries_archive_idx'));
        $this->assertCount(1, $drop);
        $this->assertStringContainsString('drop table if exists', strtolower($drop[0]['query']));
        $this->assertStringContainsString('journal_entries', $drop[0]['query']);
    }

    public static function grammars(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class],
        ];
    }
}
