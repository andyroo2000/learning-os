<?php

namespace Tests\Unit\Media;

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
 * Pins imported media provenance DDL across SQLite, PostgreSQL, and MySQL.
 */
class MediaAssetImportSourceMetadataMigrationTest extends TestCase
{
    #[DataProvider('mediaImportSourceMetadataSqlProvider')]
    public function test_media_import_source_metadata_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->mediaImportSourceMetadataBlueprint($connection)->toSql();
        $dropSql = $this->dropMediaImportSourceMetadataBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_media_import_source_metadata_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ($this->mediaImportSourceMetadataIndexNames() as $indexName) {
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
    public static function mediaImportSourceMetadataSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "media_assets" add column "import_job_id" varchar',
                    'alter table "media_assets" add column "source_kind" varchar',
                    'alter table "media_assets" add column "source_media_ref" varchar',
                    'alter table "media_assets" add column "source_filename" varchar',
                    'create index "'.self::importJobIndex().'" on "media_assets" ("import_job_id")',
                    'create unique index "'.self::importSourceMediaUnique().'" on "media_assets" ("import_job_id", "source_media_ref")',
                ],
                [
                    'drop index "'.self::importSourceMediaUnique().'"',
                    'drop index "'.self::importJobIndex().'"',
                    'alter table "media_assets" drop column "import_job_id"',
                    'alter table "media_assets" drop column "source_kind"',
                    'alter table "media_assets" drop column "source_media_ref"',
                    'alter table "media_assets" drop column "source_filename"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "media_assets" add column "import_job_id" char(26) null',
                    'alter table "media_assets" add column "source_kind" varchar(64) null',
                    'alter table "media_assets" add column "source_media_ref" varchar(255) null',
                    'alter table "media_assets" add column "source_filename" varchar(255) null',
                    'create index "'.self::importJobIndex().'" on "media_assets" ("import_job_id")',
                    'alter table "media_assets" add constraint "'.self::importSourceMediaUnique().'" unique ("import_job_id", "source_media_ref")',
                ],
                [
                    'alter table "media_assets" drop constraint "'.self::importSourceMediaUnique().'"',
                    'drop index "'.self::importJobIndex().'"',
                    'alter table "media_assets" drop column "import_job_id", drop column "source_kind", drop column "source_media_ref", drop column "source_filename"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `media_assets` add `import_job_id` char(26) null after `user_id`',
                    'alter table `media_assets` add `source_kind` varchar(64) null after `import_job_id`',
                    'alter table `media_assets` add `source_media_ref` varchar(255) null after `source_kind`',
                    'alter table `media_assets` add `source_filename` varchar(255) null after `source_media_ref`',
                    'alter table `media_assets` add index `'.self::importJobIndex().'`(`import_job_id`)',
                    'alter table `media_assets` add unique `'.self::importSourceMediaUnique().'`(`import_job_id`, `source_media_ref`)',
                ],
                [
                    'alter table `media_assets` drop index `'.self::importSourceMediaUnique().'`',
                    'alter table `media_assets` drop index `'.self::importJobIndex().'`',
                    'alter table `media_assets` drop `import_job_id`, drop `source_kind`, drop `source_media_ref`, drop `source_filename`',
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

    private function mediaImportSourceMetadataBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'media_assets', function (Blueprint $table): void {
            $table->ulid('import_job_id')
                ->nullable()
                ->after('user_id');
            $table->string('source_kind', 64)
                ->nullable()
                ->after('import_job_id');
            $table->string('source_media_ref')
                ->nullable()
                ->after('source_kind');
            $table->string('source_filename')
                ->nullable()
                ->after('source_media_ref');

            $table->index('import_job_id', self::importJobIndex());
            $table->unique(['import_job_id', 'source_media_ref'], self::importSourceMediaUnique());
        });
    }

    private function dropMediaImportSourceMetadataBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'media_assets', function (Blueprint $table): void {
            $table->dropUnique(self::importSourceMediaUnique());
            $table->dropIndex(self::importJobIndex());
            $table->dropColumn([
                'import_job_id',
                'source_kind',
                'source_media_ref',
                'source_filename',
            ]);
        });
    }

    /**
     * @return list<string>
     */
    private function mediaImportSourceMetadataIndexNames(): array
    {
        return [
            self::importJobIndex(),
            self::importSourceMediaUnique(),
        ];
    }

    private static function importJobIndex(): string
    {
        static $indexName = null;

        if ($indexName !== null) {
            return $indexName;
        }

        $migration = require LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_06_05_032000_add_import_source_metadata_to_media_assets_table.php';

        return $indexName = $migration::IMPORT_JOB_INDEX;
    }

    private static function importSourceMediaUnique(): string
    {
        static $indexName = null;

        if ($indexName !== null) {
            return $indexName;
        }

        $migration = require LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_06_05_032000_add_import_source_metadata_to_media_assets_table.php';

        return $indexName = $migration::IMPORT_SOURCE_MEDIA_UNIQUE;
    }
}
