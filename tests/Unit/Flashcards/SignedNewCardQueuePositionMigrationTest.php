<?php

namespace Tests\Unit\Flashcards;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SignedNewCardQueuePositionMigrationTest extends TestCase
{
    private const MIGRATION = '/database/migrations/2026_08_31_170000_make_new_card_queue_positions_signed.php';

    public function test_migration_exists_and_keeps_sqlite_on_its_native_signed_64_bit_integer(): void
    {
        $source = file_get_contents(LEARNING_OS_PROJECT_ROOT.self::MIGRATION);

        $this->assertIsString($source);
        $this->assertStringContainsString("getDriverName() === 'sqlite'", $source);
        $this->assertStringContainsString("bigInteger('new_queue_position')->nullable()->change()", $source);
        $this->assertStringContainsString("unsignedInteger('new_queue_position')->nullable()->change()", $source);
    }

    #[DataProvider('signedPositionSqlProvider')]
    public function test_signed_position_and_rollback_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedUpSql,
        array $expectedDownSql,
    ): void {
        $connection = new $connectionClass(new PDO('sqlite::memory:'), 'testing');
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $this->assertSame($expectedUpSql, $this->changeBlueprint($connection, false)->toSql());
        $this->assertSame($expectedDownSql, $this->changeBlueprint($connection, true)->toSql());
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function signedPositionSqlProvider(): array
    {
        return [
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "cards" alter column "new_queue_position" type bigint, alter column "new_queue_position" drop not null, alter column "new_queue_position" drop default, alter column "new_queue_position" drop identity if exists',
                    'comment on column "cards"."new_queue_position" is NULL',
                ],
                [
                    'alter table "cards" alter column "new_queue_position" type integer, alter column "new_queue_position" drop not null, alter column "new_queue_position" drop default, alter column "new_queue_position" drop identity if exists',
                    'comment on column "cards"."new_queue_position" is NULL',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                ['alter table `cards` modify `new_queue_position` bigint null'],
                ['alter table `cards` modify `new_queue_position` int unsigned null'],
            ],
        ];
    }

    private function changeBlueprint(Connection $connection, bool $rollback): Blueprint
    {
        // Keep this compile-only blueprint synchronized with the production migration.
        return new Blueprint($connection, 'cards', function (Blueprint $table) use ($rollback): void {
            $definition = $rollback
                ? $table->unsignedInteger('new_queue_position')
                : $table->bigInteger('new_queue_position');

            $definition->nullable()->change();
        });
    }
}
