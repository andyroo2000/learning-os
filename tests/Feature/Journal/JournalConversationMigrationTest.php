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

class JournalConversationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_migration_rolls_back_and_reapplies(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('journal_entries', 'root_entry_id'));
        $migration->up();
        $this->assertTrue(Schema::hasColumns('journal_entries', ['root_entry_id', 'question_id', 'conversation_position']));
    }

    #[DataProvider('connections')]
    public function test_real_migration_compiles_portable_up_and_down_sql(string $class): void
    {
        $connection = new $class(new PDO('sqlite::memory:'));
        $connection->useDefaultSchemaGrammar();
        $sql = [];
        Schema::shouldReceive('table')->twice()->with('journal_entries', \Mockery::on(function ($callback) use ($connection, &$sql) {
            $blueprint = new Blueprint($connection, 'journal_entries', $callback);
            $sql[] = implode(';', $blueprint->toSql());

            return true;
        }));
        $migration = $this->migration();
        $migration->up();
        $migration->down();
        $up = str_replace(['"', '`'], '', strtolower($sql[0]));
        $down = str_replace(['"', '`'], '', strtolower($sql[1]));
        $this->assertStringContainsString('references journal_entries (id) on delete cascade', $up);
        $this->assertStringContainsString('references journal_questions (id) on delete set null', $up);
        $this->assertMatchesRegularExpression('/journal_conversation_order_idx.*\(root_entry_id, conversation_position\)/', $up);
        foreach (['journal_entries_question_unique', 'journal_conversation_order_idx', 'journal_entries_question_id_foreign', 'journal_entries_root_entry_id_foreign'] as $name) {
            $this->assertLessThanOrEqual(63, strlen($name));
            $this->assertStringContainsString($name, $down);
        }
        $this->assertStringContainsString('conversation_position', $down);
    }

    public static function connections(): array
    {
        return [[PostgresConnection::class], [MySqlConnection::class]];
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_13_170000_add_journal_conversation_entries.php');
    }
}
