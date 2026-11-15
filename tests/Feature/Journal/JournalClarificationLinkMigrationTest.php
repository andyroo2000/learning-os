<?php

namespace Tests\Feature\Journal;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalClarificationLinkMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_migration_rolls_back_and_reapplies(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('journal_analyses', 'clarification_id'));
        $migration->up();
        $this->assertTrue(Schema::hasColumns('journal_analyses', ['clarification_id']));
    }

    #[DataProvider('connections')]
    public function test_real_migration_compiles_portable_up_and_down_sql(string $class): void
    {
        // SQLite PDO is only a compile-time stand-in; no dialect-specific SQL executes.
        $connection = new $class(new PDO('sqlite::memory:'));
        $connection->useDefaultSchemaGrammar();
        $sql = [];
        Schema::shouldReceive('table')->twice()->with('journal_analyses', \Mockery::on(function ($callback) use ($connection, &$sql) {
            $blueprint = new Blueprint($connection, 'journal_analyses', $callback);
            $sql[] = implode(';', $blueprint->toSql());

            return true;
        }));
        $migration = $this->migration();
        $migration->up();
        $migration->down();
        $up = str_replace(['"', '`'], '', strtolower($sql[0]));
        $down = str_replace(['"', '`'], '', strtolower($sql[1]));
        $this->assertStringContainsString('references journal_clarifications (id) on delete set null', $up);
        $this->assertStringContainsString('journal_analyses_clarification_id_foreign', $down);
        $this->assertStringContainsString('clarification_id', $down);
        $this->assertLessThanOrEqual(63, strlen('journal_analyses_clarification_id_foreign'));
    }

    public static function connections(): array
    {
        return [[PostgresConnection::class], [MySqlConnection::class]];
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_15_040000_link_journal_analysis_clarification.php');
    }
}
