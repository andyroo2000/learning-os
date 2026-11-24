<?php

namespace Tests\Unit\Admin;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BetaRequestMigrationTest extends TestCase
{
    #[DataProvider('databases')]
    public function test_schema_compiles_on_supported_databases(string $connectionClass, string $grammarClass): void
    {
        $connection = new $connectionClass(new \PDO('sqlite::memory:'), 'testing');
        $connection->setSchemaGrammar(new $grammarClass($connection));
        Schema::shouldReceive('create')->once()->with('convolab_beta_requests', \Mockery::on(function ($callback) use ($connection): bool {
            $blueprint = new Blueprint($connection, 'convolab_beta_requests');
            $blueprint->create();
            $callback($blueprint);
            $sql = implode(';', $blueprint->toSql());
            $this->assertStringContainsString('convolab_beta_requests_email_unique', $sql);
            $this->assertStringContainsString('beta_requests_inbox_order', $sql);

            return true;
        }));
        Schema::shouldReceive('dropIfExists')->once()->with('convolab_beta_requests');
        $migration = require database_path('migrations/2026_11_24_000000_create_convolab_beta_requests_table.php');
        $migration->up();
        $migration->down();
    }

    public static function databases(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class],
        ];
    }
}
