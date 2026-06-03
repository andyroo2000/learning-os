<?php

namespace Tests\Unit\Media;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the historical cleanup migration's delete predicate; the migration slug is intentionally pinned.
 */
class CardMediaPivotCleanupTest extends TestCase
{
    #[DataProvider('portableGrammarProvider')]
    public function test_pair_delete_constraint_compiles_to_portable_sql(string $grammarClass): void
    {
        $connection = $this->connection($grammarClass);
        $grammar = $this->grammar($grammarClass, $connection);
        $query = new Builder($connection, $grammar, new Processor);
        $query->from('card_media');

        $builder = $this->cardMediaCleanupMigration()->constrainDeleteToPairs($query, [
            (object) ['card_id' => 1, 'media_asset_id' => 10],
            (object) ['card_id' => 2, 'media_asset_id' => 20],
        ]);

        $sql = strtolower($grammar->compileDelete($builder));

        $this->assertStringStartsWith('delete from ', $sql);
        $this->assertStringContainsString('card_id', $sql);
        $this->assertStringContainsString('media_asset_id', $sql);
        $this->assertStringContainsString(' or ', $sql);
        $this->assertStringNotContainsString(' join ', $sql);
        $this->assertStringNotContainsString(' using ', $sql);
        $this->assertStringNotContainsString('ctid', $sql);
        $this->assertStringNotContainsString('rowid', $sql);
        $this->assertSame([1, 10, 2, 20], $builder->getBindings());
    }

    #[DataProvider('portableGrammarProvider')]
    public function test_empty_pair_delete_constraint_is_zero_row_safe(string $grammarClass): void
    {
        $connection = $this->connection($grammarClass);
        $grammar = $this->grammar($grammarClass, $connection);
        $query = new Builder($connection, $grammar, new Processor);
        $query->from('card_media');

        $builder = $this->cardMediaCleanupMigration()->constrainDeleteToPairs($query, []);

        $sql = strtolower($grammar->compileDelete($builder));

        $this->assertStringStartsWith('delete from ', $sql);
        // The impossible raw predicate should pass through target grammars unchanged.
        $this->assertStringContainsString('1 = 0', $sql);
        $this->assertStringNotContainsString(' join ', $sql);
        $this->assertStringNotContainsString(' using ', $sql);
        $this->assertStringNotContainsString('ctid', $sql);
        $this->assertStringNotContainsString('rowid', $sql);
        $this->assertSame([], $builder->getBindings());
    }

    /**
     * @return array<string, array{class-string<Grammar>}>
     */
    public static function portableGrammarProvider(): array
    {
        return [
            'sqlite' => [SQLiteGrammar::class],
            'postgres' => [PostgresGrammar::class],
            'mysql' => [MySqlGrammar::class],
        ];
    }

    private function cardMediaCleanupMigration(): object
    {
        $migrationFiles = glob(dirname(__DIR__, 3).'/database/migrations/*_prune_cross_owner_card_media_pivots.php');

        $this->assertIsArray($migrationFiles);
        $this->assertCount(1, $migrationFiles, 'Expected exactly one cleanup migration, found: '.count($migrationFiles));

        // Use include, not require_once, so each test receives a fresh anonymous migration instance.
        $migration = include $migrationFiles[0];

        $this->assertIsObject($migration);
        $this->assertTrue(method_exists($migration, 'constrainDeleteToPairs'));

        return $migration;
    }

    /**
     * @param  class-string<Grammar>  $grammarClass
     */
    private function grammar(string $grammarClass, Connection $connection): Grammar
    {
        // Laravel 13 grammars require a connection; older userland constructors tolerate the extra argument.
        return new $grammarClass($connection);
    }

    /**
     * @param  class-string<Grammar>  $grammarClass
     */
    private function connection(string $grammarClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        // These tests compile SQL only; target connection classes keep grammar metadata aligned without live servers.
        return match ($grammarClass) {
            MySqlGrammar::class => new MySqlConnection($pdo, 'testing'),
            PostgresGrammar::class => new PostgresConnection($pdo, 'testing'),
            SQLiteGrammar::class => new SQLiteConnection($pdo, ':memory:'),
        };
    }
}
