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
 * Pins imported-card provenance DDL across SQLite, PostgreSQL, and MySQL.
 */
class CardImportSourceMetadataMigrationTest extends TestCase
{
    private const IMPORT_JOB_INDEX = 'cards_import_job_id_idx';

    private const IMPORT_SOURCE_CARD_UNIQUE = 'cards_import_source_card_unique';

    #[DataProvider('cardImportSourceMetadataSqlProvider')]
    public function test_card_import_source_metadata_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->cardImportSourceMetadataBlueprint($connection)->toSql();
        $dropSql = $this->dropCardImportSourceMetadataBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_card_import_source_metadata_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ($this->cardImportSourceMetadataIndexNames() as $indexName) {
            $this->assertLessThanOrEqual(
                63,
                strlen($indexName),
                'Index name ['.$indexName."] exceeds PostgreSQL's identifier limit.",
            );
        }
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function cardImportSourceMetadataSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "cards" add column "import_job_id" varchar',
                    'alter table "cards" add column "source_kind" varchar',
                    'alter table "cards" add column "source_card_id" integer',
                    'alter table "cards" add column "source_note_id" integer',
                    'alter table "cards" add column "source_deck_id" integer',
                    'alter table "cards" add column "source_notetype_name" varchar',
                    'alter table "cards" add column "source_template_ord" integer',
                    'create index "'.self::IMPORT_JOB_INDEX.'" on "cards" ("import_job_id")',
                    'create unique index "'.self::IMPORT_SOURCE_CARD_UNIQUE.'" on "cards" ("import_job_id", "source_card_id")',
                ],
                [
                    'drop index "'.self::IMPORT_SOURCE_CARD_UNIQUE.'"',
                    'drop index "'.self::IMPORT_JOB_INDEX.'"',
                    'alter table "cards" drop column "import_job_id"',
                    'alter table "cards" drop column "source_kind"',
                    'alter table "cards" drop column "source_card_id"',
                    'alter table "cards" drop column "source_note_id"',
                    'alter table "cards" drop column "source_deck_id"',
                    'alter table "cards" drop column "source_notetype_name"',
                    'alter table "cards" drop column "source_template_ord"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "cards" add column "import_job_id" char(26) null',
                    'alter table "cards" add column "source_kind" varchar(64) null',
                    'alter table "cards" add column "source_card_id" bigint null',
                    'alter table "cards" add column "source_note_id" bigint null',
                    'alter table "cards" add column "source_deck_id" bigint null',
                    'alter table "cards" add column "source_notetype_name" varchar(255) null',
                    'alter table "cards" add column "source_template_ord" integer null',
                    'create index "'.self::IMPORT_JOB_INDEX.'" on "cards" ("import_job_id")',
                    'alter table "cards" add constraint "'.self::IMPORT_SOURCE_CARD_UNIQUE.'" unique ("import_job_id", "source_card_id")',
                ],
                [
                    'alter table "cards" drop constraint "'.self::IMPORT_SOURCE_CARD_UNIQUE.'"',
                    'drop index "'.self::IMPORT_JOB_INDEX.'"',
                    'alter table "cards" drop column "import_job_id", drop column "source_kind", drop column "source_card_id", drop column "source_note_id", drop column "source_deck_id", drop column "source_notetype_name", drop column "source_template_ord"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `cards` add `import_job_id` char(26) null after `deck_id`',
                    'alter table `cards` add `source_kind` varchar(64) null after `import_job_id`',
                    'alter table `cards` add `source_card_id` bigint unsigned null after `source_kind`',
                    'alter table `cards` add `source_note_id` bigint unsigned null after `source_card_id`',
                    'alter table `cards` add `source_deck_id` bigint unsigned null after `source_note_id`',
                    'alter table `cards` add `source_notetype_name` varchar(255) null after `source_deck_id`',
                    'alter table `cards` add `source_template_ord` int unsigned null after `source_notetype_name`',
                    'alter table `cards` add index `'.self::IMPORT_JOB_INDEX.'`(`import_job_id`)',
                    'alter table `cards` add unique `'.self::IMPORT_SOURCE_CARD_UNIQUE.'`(`import_job_id`, `source_card_id`)',
                ],
                [
                    'alter table `cards` drop index `'.self::IMPORT_SOURCE_CARD_UNIQUE.'`',
                    'alter table `cards` drop index `'.self::IMPORT_JOB_INDEX.'`',
                    'alter table `cards` drop `import_job_id`, drop `source_kind`, drop `source_card_id`, drop `source_note_id`, drop `source_deck_id`, drop `source_notetype_name`, drop `source_template_ord`',
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

        // These blueprints compile SQL only; the PDO is never executed for non-SQLite grammars.
        return $connectionClass === SQLiteConnection::class
            ? new SQLiteConnection($pdo, ':memory:')
            : new $connectionClass($pdo, 'testing');
    }

    private function cardImportSourceMetadataBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->ulid('import_job_id')
                ->nullable()
                ->after('deck_id');
            $table->string('source_kind', 64)
                ->nullable()
                ->after('import_job_id');
            $table->unsignedBigInteger('source_card_id')
                ->nullable()
                ->after('source_kind');
            $table->unsignedBigInteger('source_note_id')
                ->nullable()
                ->after('source_card_id');
            $table->unsignedBigInteger('source_deck_id')
                ->nullable()
                ->after('source_note_id');
            $table->string('source_notetype_name')
                ->nullable()
                ->after('source_deck_id');
            $table->unsignedInteger('source_template_ord')
                ->nullable()
                ->after('source_notetype_name');

            $table->index('import_job_id', self::IMPORT_JOB_INDEX);
            $table->unique(['import_job_id', 'source_card_id'], self::IMPORT_SOURCE_CARD_UNIQUE);
        });
    }

    private function dropCardImportSourceMetadataBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropUnique(self::IMPORT_SOURCE_CARD_UNIQUE);
            $table->dropIndex(self::IMPORT_JOB_INDEX);
            $table->dropColumn([
                'import_job_id',
                'source_kind',
                'source_card_id',
                'source_note_id',
                'source_deck_id',
                'source_notetype_name',
                'source_template_ord',
            ]);
        });
    }

    /**
     * @return list<string>
     */
    private function cardImportSourceMetadataIndexNames(): array
    {
        return [
            self::IMPORT_JOB_INDEX,
            self::IMPORT_SOURCE_CARD_UNIQUE,
        ];
    }
}
