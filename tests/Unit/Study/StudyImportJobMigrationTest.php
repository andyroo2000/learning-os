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

/**
 * Pins study-import-job DDL across SQLite, PostgreSQL, and MySQL.
 */
class StudyImportJobMigrationTest extends TestCase
{
    private const USER_CREATED_INDEX = 'study_import_jobs_user_created_idx';

    private const USER_STATUS_INDEX = 'study_import_jobs_user_status_idx';

    private const USER_UPDATED_ID_INDEX = 'study_import_jobs_user_updated_id_idx';

    private const STATUS_INDEX = 'study_import_jobs_status_idx';

    #[DataProvider('studyImportJobSqlProvider')]
    public function test_study_import_jobs_table_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->createStudyImportJobsBlueprint($connection)->toSql();
        $dropSql = $this->dropStudyImportJobsBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_study_import_job_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ($this->studyImportJobIndexNames() as $indexName) {
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
    public static function studyImportJobSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create table "study_import_jobs" ("id" varchar not null, "user_id" integer not null, "status" varchar not null default \'pending\', "source_type" varchar not null default \'anki_colpkg\', "source_filename" varchar not null, "source_object_path" varchar, "source_content_type" varchar, "source_size_bytes" integer, "deck_name" varchar not null default \'Japanese\', "preview_json" text not null, "summary_json" text, "error_message" text, "started_at" datetime, "uploaded_at" datetime, "upload_expires_at" datetime, "completed_at" datetime, "created_at" datetime, "updated_at" datetime, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"))',
                    'create index "'.self::USER_CREATED_INDEX.'" on "study_import_jobs" ("user_id", "created_at")',
                    'create index "'.self::USER_STATUS_INDEX.'" on "study_import_jobs" ("user_id", "status")',
                    'create index "'.self::USER_UPDATED_ID_INDEX.'" on "study_import_jobs" ("user_id", "updated_at", "id")',
                    'create index "'.self::STATUS_INDEX.'" on "study_import_jobs" ("status")',
                ],
                [
                    'drop table if exists "study_import_jobs"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create table "study_import_jobs" ("id" char(26) not null, "user_id" bigint not null, "status" varchar(32) not null default \'pending\', "source_type" varchar(64) not null default \'anki_colpkg\', "source_filename" varchar(255) not null, "source_object_path" varchar(255) null, "source_content_type" varchar(255) null, "source_size_bytes" bigint null, "deck_name" varchar(255) not null default \'Japanese\', "preview_json" json not null, "summary_json" json null, "error_message" text null, "started_at" timestamp(0) without time zone null, "uploaded_at" timestamp(0) without time zone null, "upload_expires_at" timestamp(0) without time zone null, "completed_at" timestamp(0) without time zone null, "created_at" timestamp(0) without time zone null, "updated_at" timestamp(0) without time zone null)',
                    'alter table "study_import_jobs" add constraint "study_import_jobs_user_id_foreign" foreign key ("user_id") references "users" ("id") on delete cascade',
                    'create index "'.self::USER_CREATED_INDEX.'" on "study_import_jobs" ("user_id", "created_at")',
                    'create index "'.self::USER_STATUS_INDEX.'" on "study_import_jobs" ("user_id", "status")',
                    'create index "'.self::USER_UPDATED_ID_INDEX.'" on "study_import_jobs" ("user_id", "updated_at", "id")',
                    'create index "'.self::STATUS_INDEX.'" on "study_import_jobs" ("status")',
                    'alter table "study_import_jobs" add primary key ("id")',
                ],
                [
                    'drop table if exists "study_import_jobs"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'create table `study_import_jobs` (`id` char(26) not null, `user_id` bigint unsigned not null, `status` varchar(32) not null default \'pending\', `source_type` varchar(64) not null default \'anki_colpkg\', `source_filename` varchar(255) not null, `source_object_path` varchar(255) null, `source_content_type` varchar(255) null, `source_size_bytes` bigint unsigned null, `deck_name` varchar(255) not null default \'Japanese\', `preview_json` json not null, `summary_json` json null, `error_message` text null, `started_at` timestamp null, `uploaded_at` timestamp null, `upload_expires_at` timestamp null, `completed_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null, primary key (`id`))',
                    'alter table `study_import_jobs` add constraint `study_import_jobs_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade',
                    'alter table `study_import_jobs` add index `'.self::USER_CREATED_INDEX.'`(`user_id`, `created_at`)',
                    'alter table `study_import_jobs` add index `'.self::USER_STATUS_INDEX.'`(`user_id`, `status`)',
                    'alter table `study_import_jobs` add index `'.self::USER_UPDATED_ID_INDEX.'`(`user_id`, `updated_at`, `id`)',
                    'alter table `study_import_jobs` add index `'.self::STATUS_INDEX.'`(`status`)',
                ],
                [
                    'drop table if exists `study_import_jobs`',
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

    private function createStudyImportJobsBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_import_jobs', function (Blueprint $table): void {
            $table->create();
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('source_type', 64)->default('anki_colpkg');
            $table->string('source_filename');
            $table->string('source_object_path')->nullable();
            $table->string('source_content_type')->nullable();
            $table->unsignedBigInteger('source_size_bytes')->nullable();
            $table->string('deck_name')->default('Japanese');
            $table->json('preview_json');
            $table->json('summary_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('upload_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at'], self::USER_CREATED_INDEX);
            $table->index(['user_id', 'status'], self::USER_STATUS_INDEX);
            $table->index(['user_id', 'updated_at', 'id'], self::USER_UPDATED_ID_INDEX);
            $table->index('status', self::STATUS_INDEX);
        });
    }

    private function dropStudyImportJobsBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_import_jobs', function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }

    /**
     * @return list<string>
     */
    private function studyImportJobIndexNames(): array
    {
        return [
            self::USER_CREATED_INDEX,
            self::USER_STATUS_INDEX,
            self::USER_UPDATED_ID_INDEX,
            self::STATUS_INDEX,
        ];
    }
}
