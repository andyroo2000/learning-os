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
 * Pins study-card-draft DDL across SQLite, PostgreSQL, and MySQL.
 */
class StudyCardDraftMigrationTest extends TestCase
{
    private const USER_CREATED_ID_INDEX = 'study_card_drafts_user_created_id_idx';

    // These index constants intentionally mirror the migration so exact DDL drift is visible.
    private const USER_STATUS_UPDATED_ID_INDEX = 'study_card_drafts_user_status_updated_id_idx';

    public function test_study_card_drafts_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_06_06_123000_create_study_card_drafts_table.php',
        );

        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_06_06_124000_add_committed_card_id_to_study_card_drafts_table.php',
        );
    }

    #[DataProvider('studyCardDraftSqlProvider')]
    public function test_study_card_drafts_table_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedCreateSql,
        array $expectedDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $createSql = $this->createStudyCardDraftsBlueprint($connection)->toSql();
        $dropSql = $this->dropStudyCardDraftsBlueprint($connection)->toSql();

        $this->assertSame($expectedCreateSql, $createSql);
        $this->assertSame($expectedDropSql, $dropSql);
    }

    public function test_study_card_draft_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ($this->studyCardDraftIndexNames() as $indexName) {
            $this->assertLessThanOrEqual(
                63,
                strlen($indexName),
                'Index name ['.$indexName."] exceeds PostgreSQL's identifier limit.",
            );
        }
    }

    #[DataProvider('committedCardIdSqlProvider')]
    public function test_committed_card_id_migration_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedAlterSql,
        array $expectedDropColumnSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $alterSql = $this->addCommittedCardIdBlueprint($connection)->toSql();
        $dropColumnSql = $this->dropCommittedCardIdBlueprint($connection)->toSql();

        $this->assertSame($expectedAlterSql, $alterSql);
        $this->assertSame($expectedDropColumnSql, $dropColumnSql);
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function studyCardDraftSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create table "study_card_drafts" ("id" varchar not null, "user_id" integer not null, "status" varchar not null default \'generating\', "creation_kind" varchar not null, "card_type" varchar not null, "prompt_json" text not null, "answer_json" text not null, "image_placement" varchar not null default \'none\', "image_prompt" text, "preview_audio_json" text, "preview_audio_role" varchar, "preview_image_json" text, "error_message" text, "created_at" datetime, "updated_at" datetime, foreign key("user_id") references "users"("id") on delete cascade, primary key ("id"))',
                    'create index "'.self::USER_CREATED_ID_INDEX.'" on "study_card_drafts" ("user_id", "created_at", "id")',
                    'create index "'.self::USER_STATUS_UPDATED_ID_INDEX.'" on "study_card_drafts" ("user_id", "status", "updated_at", "id")',
                ],
                [
                    'drop table if exists "study_card_drafts"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create table "study_card_drafts" ("id" char(26) not null, "user_id" bigint not null, "status" varchar(32) not null default \'generating\', "creation_kind" varchar(32) not null, "card_type" varchar(32) not null, "prompt_json" json not null, "answer_json" json not null, "image_placement" varchar(16) not null default \'none\', "image_prompt" text null, "preview_audio_json" json null, "preview_audio_role" varchar(16) null, "preview_image_json" json null, "error_message" text null, "created_at" timestamp(0) without time zone null, "updated_at" timestamp(0) without time zone null)',
                    'alter table "study_card_drafts" add constraint "study_card_drafts_user_id_foreign" foreign key ("user_id") references "users" ("id") on delete cascade',
                    'create index "'.self::USER_CREATED_ID_INDEX.'" on "study_card_drafts" ("user_id", "created_at", "id")',
                    'create index "'.self::USER_STATUS_UPDATED_ID_INDEX.'" on "study_card_drafts" ("user_id", "status", "updated_at", "id")',
                    'alter table "study_card_drafts" add primary key ("id")',
                ],
                [
                    'drop table if exists "study_card_drafts"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'create table `study_card_drafts` (`id` char(26) not null, `user_id` bigint unsigned not null, `status` varchar(32) not null default \'generating\', `creation_kind` varchar(32) not null, `card_type` varchar(32) not null, `prompt_json` json not null, `answer_json` json not null, `image_placement` varchar(16) not null default \'none\', `image_prompt` text null, `preview_audio_json` json null, `preview_audio_role` varchar(16) null, `preview_image_json` json null, `error_message` text null, `created_at` timestamp null, `updated_at` timestamp null, primary key (`id`))',
                    'alter table `study_card_drafts` add constraint `study_card_drafts_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade',
                    'alter table `study_card_drafts` add index `'.self::USER_CREATED_ID_INDEX.'`(`user_id`, `created_at`, `id`)',
                    'alter table `study_card_drafts` add index `'.self::USER_STATUS_UPDATED_ID_INDEX.'`(`user_id`, `status`, `updated_at`, `id`)',
                ],
                [
                    'drop table if exists `study_card_drafts`',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}>
     */
    public static function committedCardIdSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "study_card_drafts" add column "committed_card_id" varchar',
                ],
                [
                    'alter table "study_card_drafts" drop column "committed_card_id"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "study_card_drafts" add column "committed_card_id" char(26) null',
                ],
                [
                    'alter table "study_card_drafts" drop column "committed_card_id"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `study_card_drafts` add `committed_card_id` char(26) null',
                ],
                [
                    'alter table `study_card_drafts` drop `committed_card_id`',
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

    private function createStudyCardDraftsBlueprint(Connection $connection): Blueprint
    {
        // Keep this blueprint in lockstep with the migration; these fixtures intentionally pin
        // grammar-specific SQL so PostgreSQL/MySQL drift fails before a deployment.
        return new Blueprint($connection, 'study_card_drafts', function (Blueprint $table): void {
            $table->create();
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('generating');
            $table->string('creation_kind', 32);
            $table->string('card_type', 32);
            $table->json('prompt_json');
            $table->json('answer_json');
            $table->string('image_placement', 16)->default('none');
            $table->text('image_prompt')->nullable();
            $table->json('preview_audio_json')->nullable();
            $table->string('preview_audio_role', 16)->nullable();
            $table->json('preview_image_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at', 'id'], self::USER_CREATED_ID_INDEX);
            $table->index(['user_id', 'status', 'updated_at', 'id'], self::USER_STATUS_UPDATED_ID_INDEX);
        });
    }

    private function dropStudyCardDraftsBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_card_drafts', function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }

    private function addCommittedCardIdBlueprint(Connection $connection): Blueprint
    {
        // Keep this blueprint in lockstep with the follow-up migration that records draft commit retries.
        return new Blueprint($connection, 'study_card_drafts', function (Blueprint $table): void {
            $table->ulid('committed_card_id')->nullable();
        });
    }

    private function dropCommittedCardIdBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_card_drafts', function (Blueprint $table): void {
            $table->dropColumn('committed_card_id');
        });
    }

    /**
     * @return list<string>
     */
    private function studyCardDraftIndexNames(): array
    {
        return [
            self::USER_CREATED_ID_INDEX,
            self::USER_STATUS_UPDATED_ID_INDEX,
        ];
    }
}
