<?php

namespace Tests\Unit\Japanese;

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

class KanjiKnowledgeMigrationTest extends TestCase
{
    #[DataProvider('grammarProvider')]
    public function test_knowledge_tables_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        foreach ($this->blueprints($connection) as $blueprint) {
            $sql = $blueprint->toSql();
            $this->assertNotEmpty($sql);
            $this->assertStringContainsString('create table', strtolower(implode(' ', $sql)));
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

    /** @return list<Blueprint> */
    private function blueprints(Connection $connection): array
    {
        return [
            new Blueprint($connection, 'japanese_knowledge_profiles', function (Blueprint $table): void {
                $table->create();
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('knowledge_version')->default(0);
                $table->timestamps();
            }),
            new Blueprint($connection, 'wanikani_connections', function (Blueprint $table): void {
                $table->create();
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->text('api_token');
                $table->timestamp('assignments_synced_through_at', 6)->nullable();
                $table->timestamp('last_synced_at', 6)->nullable();
                $table->timestamps();
            }),
            new Blueprint($connection, 'user_known_kanji', function (Blueprint $table): void {
                $table->create();
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('character', 4);
                $table->unsignedBigInteger('wanikani_subject_id')->nullable();
                $table->timestamp('wanikani_passed_at', 6)->nullable();
                $table->timestamp('manually_added_at', 6)->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'character'], 'known_kanji_user_character_unique');
                $table->unique(['user_id', 'wanikani_subject_id'], 'known_kanji_user_wanikani_subject_unique');
            }),
        ];
    }
}
