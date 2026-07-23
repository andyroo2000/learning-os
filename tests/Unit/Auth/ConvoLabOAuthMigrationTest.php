<?php

namespace Tests\Unit\Auth;

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

class ConvoLabOAuthMigrationTest extends TestCase
{
    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_23_000000_create_convolab_oauth_identities.php',
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_schema_and_rollback_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $upSql = implode("\n", $this->identityBlueprint($connection)->toSql());
        $downSql = implode("\n", $this->dropTableBlueprint($connection)->toSql());

        foreach ([
            'convolab_oauth_identities',
            'user_id',
            'provider',
            'provider_id',
            'access_granted_at',
            'convolab_oauth_provider_identity_unique',
            'convolab_oauth_user_provider_unique',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $upSql);
        }
        $this->assertStringContainsString('convolab_oauth_identities', $downSql);
    }

    public function test_constraint_names_fit_the_postgres_identifier_limit(): void
    {
        foreach ([
            'convolab_oauth_identities_user_id_foreign',
            'convolab_oauth_provider_identity_unique',
            'convolab_oauth_user_provider_unique',
        ] as $name) {
            $this->assertLessThanOrEqual(63, strlen($name), "Database identifier [{$name}] is too long.");
        }
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

    private function identityBlueprint(Connection $connection): Blueprint
    {
        // Compile-only mirror of the migration; keep both definitions aligned when the schema changes.
        return new Blueprint($connection, 'convolab_oauth_identities', function (Blueprint $table): void {
            $table->create();
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_id');
            $table->timestampTz('access_granted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['provider', 'provider_id'], 'convolab_oauth_provider_identity_unique');
            $table->unique(['user_id', 'provider'], 'convolab_oauth_user_provider_unique');
        });
    }

    private function dropTableBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'convolab_oauth_identities', function (Blueprint $table): void {
            $table->drop();
        });
    }
}
