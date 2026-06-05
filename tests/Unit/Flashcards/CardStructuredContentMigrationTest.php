<?php

namespace Tests\Unit\Flashcards;

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

/**
 * Pins structured card-content JSON DDL across SQLite, PostgreSQL, and MySQL.
 */
class CardStructuredContentMigrationTest extends TestCase
{
    #[DataProvider('cardStructuredContentSqlProvider')]
    public function test_card_structured_content_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->cardStructuredContentBlueprint($connection)->toSql();
        $dropSql = $this->dropCardStructuredContentBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function cardStructuredContentSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "cards" add column "prompt_json" text',
                    'alter table "cards" add column "answer_json" text',
                ],
                [
                    'alter table "cards" drop column "prompt_json"',
                    'alter table "cards" drop column "answer_json"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "cards" add column "prompt_json" json null',
                    'alter table "cards" add column "answer_json" json null',
                ],
                [
                    'alter table "cards" drop column "prompt_json", drop column "answer_json"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `cards` add `prompt_json` json null after `card_type`',
                    'alter table `cards` add `answer_json` json null after `prompt_json`',
                ],
                [
                    'alter table `cards` drop `prompt_json`, drop `answer_json`',
                ],
            ],
        ];
    }

    /**
     * @param  class-string<Connection>  $connectionClass
     */
    private function connection(string $connectionClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }

    private function cardStructuredContentBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->json('prompt_json')
                ->nullable()
                ->after('card_type');
            $table->json('answer_json')
                ->nullable()
                ->after('prompt_json');
        });
    }

    private function dropCardStructuredContentBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropColumn(['prompt_json', 'answer_json']);
        });
    }
}
