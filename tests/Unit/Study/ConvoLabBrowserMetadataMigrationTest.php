<?php

namespace Tests\Unit\Study;

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

class ConvoLabBrowserMetadataMigrationTest extends TestCase
{
    private const ID_UNIQUE = 'cards_convolab_id_unique';

    private const NOTE_ID_INDEX = 'cards_convolab_note_id_idx';

    public function test_migration_file_and_postgres_index_name_limits(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_16_030000_add_convolab_browser_metadata_to_cards_table.php',
        );

        foreach ([self::ID_UNIQUE, self::NOTE_ID_INDEX] as $indexName) {
            $this->assertLessThanOrEqual(63, strlen($indexName));
        }
    }

    #[DataProvider('sqlProvider')]
    public function test_browser_metadata_and_rollback_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedUp,
        array $expectedDown,
    ): void {
        $connection = new $connectionClass(new PDO('sqlite::memory:'), 'testing');
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $this->assertSame($expectedUp, $this->upBlueprint($connection)->toSql());
        $this->assertSame($expectedDown, $this->downBlueprint($connection)->toSql());
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}> */
    public static function sqlProvider(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class, [
                'alter table "cards" add column "convolab_id" varchar',
                'alter table "cards" add column "convolab_note_id" varchar',
                'alter table "cards" add column "convolab_note_created_at" datetime',
                'alter table "cards" add column "convolab_note_updated_at" datetime',
                'create unique index "'.self::ID_UNIQUE.'" on "cards" ("convolab_id")',
                'create index "'.self::NOTE_ID_INDEX.'" on "cards" ("convolab_note_id")',
            ], [
                'drop index "'.self::ID_UNIQUE.'"',
                'drop index "'.self::NOTE_ID_INDEX.'"',
                'alter table "cards" drop column "convolab_id"',
                'alter table "cards" drop column "convolab_note_id"',
                'alter table "cards" drop column "convolab_note_created_at"',
                'alter table "cards" drop column "convolab_note_updated_at"',
            ]],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class, [
                'alter table "cards" add column "convolab_id" uuid null',
                'alter table "cards" add column "convolab_note_id" uuid null',
                'alter table "cards" add column "convolab_note_created_at" timestamp(3) without time zone null',
                'alter table "cards" add column "convolab_note_updated_at" timestamp(3) without time zone null',
                'alter table "cards" add constraint "'.self::ID_UNIQUE.'" unique ("convolab_id")',
                'create index "'.self::NOTE_ID_INDEX.'" on "cards" ("convolab_note_id")',
            ], [
                'alter table "cards" drop constraint "'.self::ID_UNIQUE.'"',
                'drop index "'.self::NOTE_ID_INDEX.'"',
                'alter table "cards" drop column "convolab_id", drop column "convolab_note_id", drop column "convolab_note_created_at", drop column "convolab_note_updated_at"',
            ]],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class, [
                'alter table `cards` add `convolab_id` char(36) null',
                'alter table `cards` add `convolab_note_id` char(36) null',
                'alter table `cards` add `convolab_note_created_at` timestamp(3) null',
                'alter table `cards` add `convolab_note_updated_at` timestamp(3) null',
                'alter table `cards` add unique `'.self::ID_UNIQUE.'`(`convolab_id`)',
                'alter table `cards` add index `'.self::NOTE_ID_INDEX.'`(`convolab_note_id`)',
            ], [
                'alter table `cards` drop index `'.self::ID_UNIQUE.'`',
                'alter table `cards` drop index `'.self::NOTE_ID_INDEX.'`',
                'alter table `cards` drop `convolab_id`, drop `convolab_note_id`, drop `convolab_note_created_at`, drop `convolab_note_updated_at`',
            ]],
        ];
    }

    private function upBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->uuid('convolab_id')->nullable();
            $table->uuid('convolab_note_id')->nullable();
            $table->timestamp('convolab_note_created_at', 3)->nullable();
            $table->timestamp('convolab_note_updated_at', 3)->nullable();
            $table->unique('convolab_id', self::ID_UNIQUE);
            $table->index('convolab_note_id', self::NOTE_ID_INDEX);
        });
    }

    private function downBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropUnique(self::ID_UNIQUE);
            $table->dropIndex(self::NOTE_ID_INDEX);
            $table->dropColumn(['convolab_id', 'convolab_note_id', 'convolab_note_created_at', 'convolab_note_updated_at']);
        });
    }
}
