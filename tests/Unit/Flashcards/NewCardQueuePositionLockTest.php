<?php

namespace Tests\Unit\Flashcards;

use App\Domain\Flashcards\Support\NewCardQueuePosition;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class NewCardQueuePositionLockTest extends TestCase
{
    #[DataProvider('ownerLockSqlProvider')]
    public function test_owner_lock_uses_the_supported_database_grammar(
        string $driver,
        string $connectionClass,
        string $grammarClass,
        string $expectedSql,
    ): void {
        $connection = $this->connection($driver, $connectionClass, $grammarClass);
        $query = (new ReflectionMethod(NewCardQueuePosition::class, 'ownerLockQuery'))
            ->invoke(new NewCardQueuePosition, $connection, 7);

        $this->assertSame($expectedSql, $query->toSql());
        $this->assertSame([7], $query->getBindings());
    }

    /**
     * @return array<string, array{string, class-string<Connection>, class-string<Grammar>, string}>
     */
    public static function ownerLockSqlProvider(): array
    {
        return [
            'sqlite omits unsupported row locks' => [
                'sqlite',
                SQLiteConnection::class,
                SQLiteGrammar::class,
                'select * from "users" where "id" = ?',
            ],
            'postgres allows sync-feed foreign-key checks' => [
                'pgsql',
                DriverNamedConnection::class,
                PostgresGrammar::class,
                'select * from "users" where "id" = ? for no key update',
            ],
            'mysql uses its portable exclusive row lock' => [
                'mysql',
                MySqlConnection::class,
                MySqlGrammar::class,
                'select * from `users` where `id` = ? for update',
            ],
        ];
    }

    /**
     * @param  class-string<Connection>  $connectionClass
     * @param  class-string<Grammar>  $grammarClass
     */
    private function connection(string $driver, string $connectionClass, string $grammarClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = $connectionClass === DriverNamedConnection::class
            ? new DriverNamedConnection($pdo, 'testing', driverName: $driver)
            : new $connectionClass($pdo, 'testing');
        $connection->setQueryGrammar(new $grammarClass($connection));

        return $connection;
    }
}

final class DriverNamedConnection extends Connection
{
    public function __construct(PDO $pdo, string $database, private readonly string $driverName)
    {
        parent::__construct($pdo, $database);
    }

    public function getDriverName()
    {
        return $this->driverName;
    }

    protected function getDefaultPostProcessor()
    {
        return new Processor;
    }
}
