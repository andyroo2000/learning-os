<?php

namespace Tests\Unit\Reviews;

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

class CardLastReviewedAtTimestampPrecisionMigrationTest extends TestCase
{
    private const MIGRATION = '/database/migrations/2026_08_13_010000_preserve_card_last_reviewed_at_precision.php';

    public function test_migration_file_exists_and_keeps_the_backfill_self_contained(): void
    {
        $path = LEARNING_OS_PROJECT_ROOT.self::MIGRATION;

        $this->assertFileExists($path);

        $source = file_get_contents($path);

        $this->assertIsString($source);
        $this->assertStringContainsString("timestamp('last_reviewed_at', \$precision)->nullable()->change()", $source);
        $this->assertStringContainsString("'pgsql' => <<<'SQL'", $source);
        $this->assertStringContainsString("'mysql' => <<<'SQL'", $source);
        $this->assertStringContainsString("'sqlite' => <<<'SQL'", $source);
        $this->assertStringContainsString('MAX(reviewed_at)', $source);
        $this->assertStringContainsString('cards.last_reviewed_at IS NOT NULL', $source);
    }

    /**
     * @param  class-string<Connection>  $connectionClass
     * @param  class-string<Grammar>  $grammarClass
     * @param  list<string>  $expectedUpSql
     * @param  list<string>  $expectedDownSql
     */
    #[DataProvider('sqlProvider')]
    public function test_precision_and_rollback_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedUpSql,
        array $expectedDownSql,
    ): void {
        $connection = new $connectionClass(new PDO('sqlite::memory:'), 'testing');
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $this->assertSame($expectedUpSql, $this->changeBlueprint($connection, 3)->toSql());
        $this->assertSame($expectedDownSql, $this->changeBlueprint($connection, 0)->toSql());
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}> */
    public static function sqlProvider(): array
    {
        return [
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "cards" alter column "last_reviewed_at" type timestamp(3) without time zone, alter column "last_reviewed_at" drop not null, alter column "last_reviewed_at" drop default, alter column "last_reviewed_at" drop identity if exists',
                    'comment on column "cards"."last_reviewed_at" is NULL',
                ],
                [
                    'alter table "cards" alter column "last_reviewed_at" type timestamp(0) without time zone, alter column "last_reviewed_at" drop not null, alter column "last_reviewed_at" drop default, alter column "last_reviewed_at" drop identity if exists',
                    'comment on column "cards"."last_reviewed_at" is NULL',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                ['alter table `cards` modify `last_reviewed_at` timestamp(3) null'],
                ['alter table `cards` modify `last_reviewed_at` timestamp null'],
            ],
        ];
    }

    private function changeBlueprint(Connection $connection, int $precision): Blueprint
    {
        // Keep this compile-only blueprint synchronized with the production migration.
        return new Blueprint($connection, 'cards', function (Blueprint $table) use ($precision): void {
            $table->timestamp('last_reviewed_at', $precision)->nullable()->change();
        });
    }
}
