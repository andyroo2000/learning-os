<?php

namespace Tests\Unit\Admin;

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

final class AdminSentenceScriptActorIndexMigrationTest extends TestCase
{
    private const OLD_INDEX = 'admin_sentence_script_tests_order_idx';

    private const ACTOR_ORDER_INDEX = 'admin_sentence_script_tests_actor_order_idx';

    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_22_231000_scope_admin_sentence_script_test_order_index.php',
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_actor_order_index_compiles_up_and_down_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
        string $dropOldSql,
        string $createActorSql,
        string $dropActorSql,
        string $createOldSql,
    ): void {
        $connection = $this->connection($connectionClass, $grammarClass);

        $this->assertSame(
            [$dropOldSql, $createActorSql],
            $this->upBlueprint($connection)->toSql(),
        );
        $this->assertSame(
            [$dropActorSql, $createOldSql],
            $this->downBlueprint($connection)->toSql(),
        );
        $this->assertLessThanOrEqual(63, strlen(self::ACTOR_ORDER_INDEX));
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, string, string, string, string}> */
    public static function grammarProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                'drop index "admin_sentence_script_tests_order_idx"',
                'create index "admin_sentence_script_tests_actor_order_idx" on "admin_sentence_script_tests" ("actor_convolab_user_id", "created_at", "id")',
                'drop index "admin_sentence_script_tests_actor_order_idx"',
                'create index "admin_sentence_script_tests_order_idx" on "admin_sentence_script_tests" ("created_at", "id")',
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                'drop index "admin_sentence_script_tests_order_idx"',
                'create index "admin_sentence_script_tests_actor_order_idx" on "admin_sentence_script_tests" ("actor_convolab_user_id", "created_at", "id")',
                'drop index "admin_sentence_script_tests_actor_order_idx"',
                'create index "admin_sentence_script_tests_order_idx" on "admin_sentence_script_tests" ("created_at", "id")',
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                'alter table `admin_sentence_script_tests` drop index `admin_sentence_script_tests_order_idx`',
                'alter table `admin_sentence_script_tests` add index `admin_sentence_script_tests_actor_order_idx`(`actor_convolab_user_id`, `created_at`, `id`)',
                'alter table `admin_sentence_script_tests` drop index `admin_sentence_script_tests_actor_order_idx`',
                'alter table `admin_sentence_script_tests` add index `admin_sentence_script_tests_order_idx`(`created_at`, `id`)',
            ],
        ];
    }

    /** @param class-string<Connection> $connectionClass @param class-string<Grammar> $grammarClass */
    private function connection(string $connectionClass, string $grammarClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
        $connection->setSchemaGrammar(new $grammarClass($connection));

        return $connection;
    }

    private function upBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'admin_sentence_script_tests', function (Blueprint $table): void {
            $table->dropIndex(self::OLD_INDEX);
            $table->index(
                ['actor_convolab_user_id', 'created_at', 'id'],
                self::ACTOR_ORDER_INDEX,
            );
        });
    }

    private function downBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'admin_sentence_script_tests', function (Blueprint $table): void {
            $table->dropIndex(self::ACTOR_ORDER_INDEX);
            $table->index(['created_at', 'id'], self::OLD_INDEX);
        });
    }
}
