<?php

namespace Tests\Unit\Reviews;

use App\Domain\Reviews\Support\CardReviewCardLock;
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
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CardReviewCardLockTest extends TestCase
{
    #[DataProvider('lockSqlProvider')]
    public function test_card_review_lock_uses_canonical_order_and_target_database_lock_grammar(
        string $connectionClass,
        string $grammarClass,
        string $expectedSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $query = new Builder($connection, $grammar, new Processor);
        $query
            ->from('cards')
            ->whereIn('cards.id', [
                '01K00000000000000000000002',
                '01k00000000000000000000001',
            ]);

        $connection->beginTransaction();

        try {
            $lockedQuery = CardReviewCardLock::apply($query);
        } finally {
            $connection->rollBack();
        }

        $this->assertSame($expectedSql, $lockedQuery->toSql());
        $this->assertSame([
            '01K00000000000000000000002',
            '01k00000000000000000000001',
        ], $lockedQuery->getBindings());
    }

    public function test_card_review_lock_rejects_queries_outside_a_transaction(): void
    {
        $connection = $this->connection(SQLiteConnection::class);
        $grammar = new SQLiteGrammar($connection);
        $query = new Builder($connection, $grammar, new Processor);
        $query->from('cards');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card review row locks require an active transaction.');

        CardReviewCardLock::apply($query);
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, string}>
     */
    public static function lockSqlProvider(): array
    {
        return [
            'sqlite omits unsupported row locks' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                'select * from "cards" where "cards"."id" in (?, ?) order by LOWER(cards.id), CASE WHEN cards.id = LOWER(cards.id) THEN 0 ELSE 1 END, "cards"."id" asc',
            ],
            'postgres locks in canonical order' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                'select * from "cards" where "cards"."id" in (?, ?) order by LOWER(cards.id), CASE WHEN cards.id = LOWER(cards.id) THEN 0 ELSE 1 END, "cards"."id" asc for update',
            ],
            'mysql locks in canonical order' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                'select * from `cards` where `cards`.`id` in (?, ?) order by LOWER(cards.id), CASE WHEN cards.id = LOWER(cards.id) THEN 0 ELSE 1 END, `cards`.`id` asc for update',
            ],
        ];
    }

    /**
     * @param  class-string<Connection>  $connectionClass
     */
    private function connection(string $connectionClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        // Compile-only connections let the real target grammars prove the lock clause without live servers.
        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }
}
