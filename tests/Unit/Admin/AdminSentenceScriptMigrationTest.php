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

final class AdminSentenceScriptMigrationTest extends TestCase
{
    private const ORDER_INDEX = 'admin_sentence_script_tests_order_idx';

    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_22_220000_create_admin_sentence_script_tests_table.php',
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_table_compiles_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
        string $uuidFragment,
        string $timestampFragment,
        string $dropSql,
    ): void {
        $connection = $this->connection($connectionClass, $grammarClass);
        $sql = implode("\n", $this->createBlueprint($connection)->toSql());

        $this->assertStringContainsString($uuidFragment, $sql);
        $this->assertStringContainsString($timestampFragment, $sql);
        $this->assertStringContainsString(self::ORDER_INDEX, $sql);
        $this->assertSame([$dropSql], $this->dropBlueprint($connection)->toSql());
        $this->assertLessThanOrEqual(63, strlen(self::ORDER_INDEX));
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, string, string, string}> */
    public static function grammarProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class, '"id" varchar not null', '"created_at" datetime not null', 'drop table if exists "admin_sentence_script_tests"'],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class, '"id" uuid not null', 'timestamp(3) with time zone not null', 'drop table if exists "admin_sentence_script_tests"'],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class, '`id` char(36) not null', '`created_at` timestamp(3) not null', 'drop table if exists `admin_sentence_script_tests`'],
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

    private function createBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'admin_sentence_script_tests', function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->uuid('actor_convolab_user_id');
            $table->text('sentence');
            $table->text('translation')->nullable();
            $table->string('target_language', 16)->default('ja');
            $table->string('native_language', 16)->default('en');
            $table->string('jlpt_level', 32)->nullable();
            $table->string('l1_voice_id');
            $table->string('l2_voice_id');
            $table->text('prompt_template');
            $table->json('units_json')->nullable();
            $table->text('raw_response');
            $table->double('estimated_duration_secs')->nullable();
            $table->text('parse_error')->nullable();
            $table->timestampTz('created_at', 3);
            $table->index(['created_at', 'id'], self::ORDER_INDEX);
        });
    }

    private function dropBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'admin_sentence_script_tests', function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }
}
