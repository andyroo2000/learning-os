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
 * Pins study vocab variant metadata DDL across SQLite, PostgreSQL, and MySQL.
 */
class StudyVariantMetadataMigrationTest extends TestCase
{
    #[DataProvider('studyVariantMetadataSqlProvider')]
    public function test_study_variant_metadata_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedDraftAlterSql,
        array $expectedCardAlterSql,
        array $expectedCardDropSql,
        array $expectedDraftDropSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $draftAlterSql = $this->addStudyCardDraftVariantMetadataBlueprint($connection)->toSql();
        $cardAlterSql = $this->addCardVariantMetadataBlueprint($connection)->toSql();
        $cardDropSql = $this->dropCardVariantMetadataBlueprint($connection)->toSql();
        $draftDropSql = $this->dropStudyCardDraftVariantMetadataBlueprint($connection)->toSql();

        $this->assertSame($expectedDraftAlterSql, $draftAlterSql);
        $this->assertSame($expectedCardAlterSql, $cardAlterSql);
        $this->assertSame($expectedCardDropSql, $cardDropSql);
        $this->assertSame($expectedDraftDropSql, $draftDropSql);
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>, list<string>, list<string>}>
     */
    public static function studyVariantMetadataSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'alter table "study_card_drafts" add column "variant_group_id" varchar',
                    'alter table "study_card_drafts" add column "variant_sentence_id" varchar',
                    'alter table "study_card_drafts" add column "variant_kind" varchar',
                    'alter table "study_card_drafts" add column "variant_stage" integer',
                    'alter table "study_card_drafts" add column "variant_status" varchar',
                    'alter table "study_card_drafts" add column "variant_unlocked_at" datetime',
                ],
                [
                    'alter table "cards" add column "variant_group_id" varchar',
                    'alter table "cards" add column "variant_sentence_id" varchar',
                    'alter table "cards" add column "variant_kind" varchar',
                    'alter table "cards" add column "variant_stage" integer',
                    'alter table "cards" add column "variant_status" varchar',
                    'alter table "cards" add column "variant_unlocked_at" datetime',
                ],
                [
                    'alter table "cards" drop column "variant_group_id"',
                    'alter table "cards" drop column "variant_sentence_id"',
                    'alter table "cards" drop column "variant_kind"',
                    'alter table "cards" drop column "variant_stage"',
                    'alter table "cards" drop column "variant_status"',
                    'alter table "cards" drop column "variant_unlocked_at"',
                ],
                [
                    'alter table "study_card_drafts" drop column "variant_group_id"',
                    'alter table "study_card_drafts" drop column "variant_sentence_id"',
                    'alter table "study_card_drafts" drop column "variant_kind"',
                    'alter table "study_card_drafts" drop column "variant_stage"',
                    'alter table "study_card_drafts" drop column "variant_status"',
                    'alter table "study_card_drafts" drop column "variant_unlocked_at"',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "study_card_drafts" add column "variant_group_id" varchar(64) null',
                    'alter table "study_card_drafts" add column "variant_sentence_id" varchar(64) null',
                    'alter table "study_card_drafts" add column "variant_kind" varchar(64) null',
                    'alter table "study_card_drafts" add column "variant_stage" smallint null',
                    'alter table "study_card_drafts" add column "variant_status" varchar(16) null',
                    'alter table "study_card_drafts" add column "variant_unlocked_at" timestamp(0) without time zone null',
                ],
                [
                    'alter table "cards" add column "variant_group_id" varchar(64) null',
                    'alter table "cards" add column "variant_sentence_id" varchar(64) null',
                    'alter table "cards" add column "variant_kind" varchar(64) null',
                    'alter table "cards" add column "variant_stage" smallint null',
                    'alter table "cards" add column "variant_status" varchar(16) null',
                    'alter table "cards" add column "variant_unlocked_at" timestamp(0) without time zone null',
                ],
                [
                    'alter table "cards" drop column "variant_group_id", drop column "variant_sentence_id", drop column "variant_kind", drop column "variant_stage", drop column "variant_status", drop column "variant_unlocked_at"',
                ],
                [
                    'alter table "study_card_drafts" drop column "variant_group_id", drop column "variant_sentence_id", drop column "variant_kind", drop column "variant_stage", drop column "variant_status", drop column "variant_unlocked_at"',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `study_card_drafts` add `variant_group_id` varchar(64) null after `preview_image_json`',
                    'alter table `study_card_drafts` add `variant_sentence_id` varchar(64) null after `variant_group_id`',
                    'alter table `study_card_drafts` add `variant_kind` varchar(64) null after `variant_sentence_id`',
                    'alter table `study_card_drafts` add `variant_stage` smallint unsigned null after `variant_kind`',
                    'alter table `study_card_drafts` add `variant_status` varchar(16) null after `variant_stage`',
                    'alter table `study_card_drafts` add `variant_unlocked_at` timestamp null after `variant_status`',
                ],
                [
                    'alter table `cards` add `variant_group_id` varchar(64) null after `scheduler_state`',
                    'alter table `cards` add `variant_sentence_id` varchar(64) null after `variant_group_id`',
                    'alter table `cards` add `variant_kind` varchar(64) null after `variant_sentence_id`',
                    'alter table `cards` add `variant_stage` smallint unsigned null after `variant_kind`',
                    'alter table `cards` add `variant_status` varchar(16) null after `variant_stage`',
                    'alter table `cards` add `variant_unlocked_at` timestamp null after `variant_status`',
                ],
                [
                    'alter table `cards` drop `variant_group_id`, drop `variant_sentence_id`, drop `variant_kind`, drop `variant_stage`, drop `variant_status`, drop `variant_unlocked_at`',
                ],
                [
                    'alter table `study_card_drafts` drop `variant_group_id`, drop `variant_sentence_id`, drop `variant_kind`, drop `variant_stage`, drop `variant_status`, drop `variant_unlocked_at`',
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

    private function addStudyCardDraftVariantMetadataBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_card_drafts', function (Blueprint $table): void {
            $table->string('variant_group_id', 64)->nullable()->after('preview_image_json');
            $table->string('variant_sentence_id', 64)->nullable()->after('variant_group_id');
            $table->string('variant_kind', 64)->nullable()->after('variant_sentence_id');
            $table->unsignedSmallInteger('variant_stage')->nullable()->after('variant_kind');
            $table->string('variant_status', 16)->nullable()->after('variant_stage');
            $table->timestamp('variant_unlocked_at')->nullable()->after('variant_status');
        });
    }

    private function addCardVariantMetadataBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->string('variant_group_id', 64)->nullable()->after('scheduler_state');
            $table->string('variant_sentence_id', 64)->nullable()->after('variant_group_id');
            $table->string('variant_kind', 64)->nullable()->after('variant_sentence_id');
            $table->unsignedSmallInteger('variant_stage')->nullable()->after('variant_kind');
            $table->string('variant_status', 16)->nullable()->after('variant_stage');
            $table->timestamp('variant_unlocked_at')->nullable()->after('variant_status');
        });
    }

    private function dropCardVariantMetadataBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'cards', function (Blueprint $table): void {
            $table->dropColumn([
                'variant_group_id',
                'variant_sentence_id',
                'variant_kind',
                'variant_stage',
                'variant_status',
                'variant_unlocked_at',
            ]);
        });
    }

    private function dropStudyCardDraftVariantMetadataBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_card_drafts', function (Blueprint $table): void {
            $table->dropColumn([
                'variant_group_id',
                'variant_sentence_id',
                'variant_kind',
                'variant_stage',
                'variant_status',
                'variant_unlocked_at',
            ]);
        });
    }
}
