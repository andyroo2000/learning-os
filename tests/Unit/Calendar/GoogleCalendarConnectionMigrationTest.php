<?php

namespace Tests\Unit\Calendar;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoogleCalendarConnectionMigrationTest extends TestCase
{
    #[DataProvider('grammarProvider')]
    public function test_connection_table_compiles_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $sql = $this->blueprint($connection)->toSql();

        $this->assertNotEmpty($sql);
        $compiled = strtolower(implode(' ', $sql));
        $this->assertStringContainsString('create table', $compiled);
        $this->assertStringContainsString('user_id', $compiled);
        $this->assertStringContainsString('unique', $compiled);
        $this->assertStringContainsString('foreign key', $compiled);
    }

    #[DataProvider('grammarProvider')]
    public function test_provider_account_unique_change_compiles_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));
        $sql = (new Blueprint($connection, 'google_calendar_connections', function (Blueprint $table): void {
            $table->string('provider_account_id', 255)->change();
            $table->unique('provider_account_id', 'google_calendar_provider_account_unique');
        }))->toSql();

        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('provider_account_id', strtolower(implode(' ', $sql)));
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>}> */
    public static function grammarProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class],
        ];
    }

    /** @param class-string<Connection> $connectionClass */
    private function connection(string $connectionClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }

    private function blueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'google_calendar_connections', function (Blueprint $table): void {
            $table->create();
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider_account_id', 1024);
            $table->string('account_email', 254)->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestampTz('token_expires_at', 6)->nullable();
            $table->json('scopes');
            $table->json('settings');
            $table->text('sync_cursors')->nullable();
            $table->timestampTz('connected_at', 6);
            $table->timestampTz('last_synced_at', 6)->nullable();
            $table->timestampsTz(6);
        });
    }
}
