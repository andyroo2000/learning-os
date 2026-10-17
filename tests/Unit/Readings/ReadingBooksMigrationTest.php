<?php

namespace Tests\Unit\Readings;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ReadingBooksMigrationTest extends TestCase
{
    #[DataProvider('databases')]
    public function test_actual_migration_compiles_constraints_and_reverses_in_dependency_order(string $connectionClass, string $grammarClass): void
    {
        $connection = new $connectionClass(new PDO('sqlite::memory:'), 'testing');
        $connection->setSchemaGrammar(new $grammarClass($connection));
        $sql = [];
        Schema::shouldReceive('create')->twice()->andReturnUsing(function ($name, $callback) use ($connection, &$sql): void {
            $blueprint = new Blueprint($connection, $name, function (Blueprint $table) use ($callback): void {
                $table->create();
                $callback($table);
            });
            $sql = [...$sql, ...$blueprint->toSql()];
        });
        $migration = require base_path('database/migrations/2026_10_17_200000_create_reading_books.php');
        $migration->up();
        $compiled = str_replace(['"', '`'], '', implode(' ', $sql));
        $this->assertStringContainsString('reading_books_user_id_created_at_id_index', $compiled);
        $this->assertStringContainsString('reading_book_entries_book_id_position_unique', $compiled);
        $this->assertStringContainsString('references readings', $compiled);
        $this->assertStringContainsString('references reading_books', $compiled);
        $this->assertStringContainsString('on delete cascade', $compiled);
        Schema::shouldReceive('dropIfExists')->once()->with('reading_book_entries')->ordered();
        Schema::shouldReceive('dropIfExists')->once()->with('reading_books')->ordered();
        $migration->down();
    }

    public static function databases(): array
    {
        return [[SQLiteConnection::class, SQLiteGrammar::class],
            [PostgresConnection::class, PostgresGrammar::class],
            [MySqlConnection::class, MySqlGrammar::class]];
    }
}
