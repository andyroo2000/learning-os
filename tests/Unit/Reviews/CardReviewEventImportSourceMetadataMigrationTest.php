<?php

namespace Tests\Unit\Reviews;

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
 * Pins imported review-event provenance DDL across SQLite, PostgreSQL, and MySQL.
 */
class CardReviewEventImportSourceMetadataMigrationTest extends TestCase
{
    private const IMPORT_JOB_INDEX = 'card_review_events_import_job_id_idx';

    private const IMPORT_SOURCE_REVIEW_UNIQUE = 'cre_import_source_review_unique';

    #[DataProvider('reviewImportSourceMetadataSqlProvider')]
    public function test_review_import_source_metadata_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->reviewImportSourceMetadataBlueprint($connection)->toSql();
        $dropSql = $this->dropReviewImportSourceMetadataBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_review_import_source_metadata_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ($this->reviewImportSourceMetadataIndexNames() as $indexName) {
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
    public static function reviewImportSourceMetadataSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "card_review_events" add column "import_job_id" varchar',
                    'alter table "card_review_events" add column "source_kind" varchar',
                    'alter table "card_review_events" add column "source_review_id" integer',
                    'alter table "card_review_events" add column "source_card_id" integer',
                    'alter table "card_review_events" add column "source_ease" integer',
                    'alter table "card_review_events" add column "source_interval" integer',
                    'alter table "card_review_events" add column "source_last_interval" integer',
                    'alter table "card_review_events" add column "source_factor" integer',
                    'alter table "card_review_events" add column "source_time_ms" integer',
                    'alter table "card_review_events" add column "source_review_type" integer',
                    'alter table "card_review_events" add column "raw_payload_json" text',
                    'create index "'.self::IMPORT_JOB_INDEX.'" on "card_review_events" ("import_job_id")',
                    'create unique index "'.self::IMPORT_SOURCE_REVIEW_UNIQUE.'" on "card_review_events" ("import_job_id", "source_review_id")',
                ],
                [
                    'drop index "'.self::IMPORT_SOURCE_REVIEW_UNIQUE.'"',
                    'drop index "'.self::IMPORT_JOB_INDEX.'"',
                    'alter table "card_review_events" drop column "import_job_id"',
                    'alter table "card_review_events" drop column "source_kind"',
                    'alter table "card_review_events" drop column "source_review_id"',
                    'alter table "card_review_events" drop column "source_card_id"',
                    'alter table "card_review_events" drop column "source_ease"',
                    'alter table "card_review_events" drop column "source_interval"',
                    'alter table "card_review_events" drop column "source_last_interval"',
                    'alter table "card_review_events" drop column "source_factor"',
                    'alter table "card_review_events" drop column "source_time_ms"',
                    'alter table "card_review_events" drop column "source_review_type"',
                    'alter table "card_review_events" drop column "raw_payload_json"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "card_review_events" add column "import_job_id" char(26) null',
                    'alter table "card_review_events" add column "source_kind" varchar(64) null',
                    'alter table "card_review_events" add column "source_review_id" bigint null',
                    'alter table "card_review_events" add column "source_card_id" bigint null',
                    'alter table "card_review_events" add column "source_ease" integer null',
                    'alter table "card_review_events" add column "source_interval" integer null',
                    'alter table "card_review_events" add column "source_last_interval" integer null',
                    'alter table "card_review_events" add column "source_factor" integer null',
                    'alter table "card_review_events" add column "source_time_ms" integer null',
                    'alter table "card_review_events" add column "source_review_type" integer null',
                    'alter table "card_review_events" add column "raw_payload_json" json null',
                    'create index "'.self::IMPORT_JOB_INDEX.'" on "card_review_events" ("import_job_id")',
                    'alter table "card_review_events" add constraint "'.self::IMPORT_SOURCE_REVIEW_UNIQUE.'" unique ("import_job_id", "source_review_id")',
                ],
                [
                    'alter table "card_review_events" drop constraint "'.self::IMPORT_SOURCE_REVIEW_UNIQUE.'"',
                    'drop index "'.self::IMPORT_JOB_INDEX.'"',
                    'alter table "card_review_events" drop column "import_job_id", drop column "source_kind", drop column "source_review_id", drop column "source_card_id", drop column "source_ease", drop column "source_interval", drop column "source_last_interval", drop column "source_factor", drop column "source_time_ms", drop column "source_review_type", drop column "raw_payload_json"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `card_review_events` add `import_job_id` char(26) null after `card_id`',
                    'alter table `card_review_events` add `source_kind` varchar(64) null after `import_job_id`',
                    'alter table `card_review_events` add `source_review_id` bigint unsigned null after `source_kind`',
                    'alter table `card_review_events` add `source_card_id` bigint unsigned null after `source_review_id`',
                    'alter table `card_review_events` add `source_ease` int null after `source_card_id`',
                    'alter table `card_review_events` add `source_interval` int null after `source_ease`',
                    'alter table `card_review_events` add `source_last_interval` int null after `source_interval`',
                    'alter table `card_review_events` add `source_factor` int null after `source_last_interval`',
                    'alter table `card_review_events` add `source_time_ms` int unsigned null after `source_factor`',
                    'alter table `card_review_events` add `source_review_type` int null after `source_time_ms`',
                    'alter table `card_review_events` add `raw_payload_json` json null after `source_review_type`',
                    'alter table `card_review_events` add index `'.self::IMPORT_JOB_INDEX.'`(`import_job_id`)',
                    'alter table `card_review_events` add unique `'.self::IMPORT_SOURCE_REVIEW_UNIQUE.'`(`import_job_id`, `source_review_id`)',
                ],
                [
                    'alter table `card_review_events` drop index `'.self::IMPORT_SOURCE_REVIEW_UNIQUE.'`',
                    'alter table `card_review_events` drop index `'.self::IMPORT_JOB_INDEX.'`',
                    'alter table `card_review_events` drop `import_job_id`, drop `source_kind`, drop `source_review_id`, drop `source_card_id`, drop `source_ease`, drop `source_interval`, drop `source_last_interval`, drop `source_factor`, drop `source_time_ms`, drop `source_review_type`, drop `raw_payload_json`',
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

    private function reviewImportSourceMetadataBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'card_review_events', function (Blueprint $table): void {
            $table->ulid('import_job_id')
                ->nullable()
                ->after('card_id');
            $table->string('source_kind', 64)
                ->nullable()
                ->after('import_job_id');
            $table->unsignedBigInteger('source_review_id')
                ->nullable()
                ->after('source_kind');
            $table->unsignedBigInteger('source_card_id')
                ->nullable()
                ->after('source_review_id');
            $table->integer('source_ease')
                ->nullable()
                ->after('source_card_id');
            $table->integer('source_interval')
                ->nullable()
                ->after('source_ease');
            $table->integer('source_last_interval')
                ->nullable()
                ->after('source_interval');
            $table->integer('source_factor')
                ->nullable()
                ->after('source_last_interval');
            $table->unsignedInteger('source_time_ms')
                ->nullable()
                ->after('source_factor');
            $table->integer('source_review_type')
                ->nullable()
                ->after('source_time_ms');
            $table->json('raw_payload_json')
                ->nullable()
                ->after('source_review_type');

            $table->index('import_job_id', self::IMPORT_JOB_INDEX);
            $table->unique(['import_job_id', 'source_review_id'], self::IMPORT_SOURCE_REVIEW_UNIQUE);
        });
    }

    private function dropReviewImportSourceMetadataBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'card_review_events', function (Blueprint $table): void {
            $table->dropUnique(self::IMPORT_SOURCE_REVIEW_UNIQUE);
            $table->dropIndex(self::IMPORT_JOB_INDEX);
            $table->dropColumn([
                'import_job_id',
                'source_kind',
                'source_review_id',
                'source_card_id',
                'source_ease',
                'source_interval',
                'source_last_interval',
                'source_factor',
                'source_time_ms',
                'source_review_type',
                'raw_payload_json',
            ]);
        });
    }

    /**
     * @return list<string>
     */
    private function reviewImportSourceMetadataIndexNames(): array
    {
        return [
            self::IMPORT_JOB_INDEX,
            self::IMPORT_SOURCE_REVIEW_UNIQUE,
        ];
    }
}
