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

final class AdminScriptLabAudioMigrationTest extends TestCase
{
    private const ACTOR_INDEX = 'admin_script_lab_audio_actor_created_idx';

    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_22_230000_create_admin_script_lab_audio_renderings_table.php',
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
        $this->assertStringContainsString(self::ACTOR_INDEX, $sql);
        $this->assertSame([$dropSql], $this->dropBlueprint($connection)->toSql());
        $this->assertLessThanOrEqual(63, strlen(self::ACTOR_INDEX));
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, string, string, string}> */
    public static function grammarProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class, '"id" varchar not null', '"created_at" datetime not null', 'drop table if exists "admin_script_lab_audio_renderings"'],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class, '"id" uuid not null', 'timestamp(3) with time zone not null', 'drop table if exists "admin_script_lab_audio_renderings"'],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class, '`id` char(36) not null', '`created_at` timestamp(3) not null', 'drop table if exists `admin_script_lab_audio_renderings`'],
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
        // Keep this compile-only fixture synchronized with the production migration.
        return new Blueprint($connection, 'admin_script_lab_audio_renderings', function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->uuid('actor_convolab_user_id');
            $table->text('original_text');
            $table->text('synthesized_text');
            $table->string('voice_id');
            $table->double('speed')->default(1);
            $table->string('format')->nullable();
            $table->double('duration_seconds')->nullable();
            $table->text('audio_storage_path');
            $table->timestampTz('created_at', 3);
            $table->index(
                ['actor_convolab_user_id', 'created_at'],
                self::ACTOR_INDEX,
            );
        });
    }

    private function dropBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'admin_script_lab_audio_renderings', function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }
}
