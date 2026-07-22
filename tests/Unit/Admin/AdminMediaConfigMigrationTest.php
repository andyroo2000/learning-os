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

class AdminMediaConfigMigrationTest extends TestCase
{
    private const ORDER_INDEX = 'admin_speaker_avatars_order_idx';

    #[DataProvider('portableGrammarProvider')]
    public function test_media_config_tables_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
        string $jsonFragment,
        string $timestampFragment,
    ): void {
        $connection = $this->connection($connectionClass, $grammarClass);

        $speakerSql = implode("\n", $this->speakerAvatarBlueprint($connection)->toSql());
        $dictionarySql = implode("\n", $this->pronunciationDictionaryBlueprint($connection)->toSql());

        $this->assertStringContainsString(self::ORDER_INDEX, $speakerSql);
        $this->assertStringContainsString($timestampFragment, $speakerSql);
        $this->assertStringContainsString($jsonFragment, $dictionarySql);
        $this->assertStringContainsString($timestampFragment, $dictionarySql);
    }

    #[DataProvider('dropTableSqlProvider')]
    public function test_media_config_rollbacks_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
        array $expectedSql,
    ): void {
        $connection = $this->connection($connectionClass, $grammarClass);
        $blueprint = new Blueprint($connection, 'admin_speaker_avatars', function (Blueprint $table): void {
            $table->dropIfExists();
        });

        $this->assertSame($expectedSql, $blueprint->toSql());
    }

    public function test_portability_targets_and_postgres_identifier_limit_stay_explicit(): void
    {
        $this->assertSame(['sqlite', 'postgres', 'mysql'], array_keys(self::portableGrammarProvider()));
        $this->assertLessThanOrEqual(63, strlen(self::ORDER_INDEX));
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, string, string}>
     */
    public static function portableGrammarProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class, '"keep_kanji" text not null', 'datetime'],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class, '"keep_kanji" json not null', 'timestamp(3) with time zone'],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class, '`keep_kanji` json not null', 'timestamp(3)'],
        ];
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>}>
     */
    public static function dropTableSqlProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class, ['drop table if exists "admin_speaker_avatars"']],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class, ['drop table if exists "admin_speaker_avatars"']],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class, ['drop table if exists `admin_speaker_avatars`']],
        ];
    }

    /**
     * @param  class-string<Connection>  $connectionClass
     * @param  class-string<Grammar>  $grammarClass
     */
    private function connection(string $connectionClass, string $grammarClass): Connection
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
        $connection->setSchemaGrammar(new $grammarClass($connection));

        return $connection;
    }

    private function speakerAvatarBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'admin_speaker_avatars', function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->string('filename')->unique();
            $table->text('cropped_url');
            $table->text('original_url');
            $table->string('language', 16);
            $table->string('gender', 16);
            $table->string('tone', 16);
            $table->string('source_system', 32)->default('convolab');
            $table->timestampTz('created_at', 3);
            $table->timestampTz('updated_at', 3);
            $table->index(['language', 'gender', 'tone', 'id'], self::ORDER_INDEX);
        });
    }

    private function pronunciationDictionaryBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'admin_pronunciation_dictionaries', function (Blueprint $table): void {
            $table->create();
            $table->string('locale', 16)->primary();
            $table->json('keep_kanji');
            $table->json('force_kana');
            $table->json('verb_kana');
            $table->timestampTz('updated_at', 3)->nullable();
        });
    }
}
