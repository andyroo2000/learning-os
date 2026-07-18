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

class StudyVocabBundleMigrationTest extends TestCase
{
    private const IDENTIFIERS = [
        'study_vocab_variant_groups_user_id_foreign',
        'study_vocab_groups_user_created_id_idx',
        'study_vocab_groups_user_target_idx',
        'study_vocab_variant_sentences_user_id_foreign',
        'study_vocab_variant_sentences_variant_group_id_foreign',
        'study_vocab_sentences_group_ordinal_unique',
        'study_vocab_sentences_user_group_idx',
    ];

    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_18_190000_create_study_vocab_bundle_tables.php',
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_both_tables_and_reverse_order_drops_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $this->assertNotEmpty($this->groupBlueprint($connection)->toSql());
        $this->assertNotEmpty($this->sentenceBlueprint($connection)->toSql());
        $this->assertStringContainsString(
            'study_vocab_variant_sentences',
            implode(' ', $this->dropBlueprint($connection, 'study_vocab_variant_sentences')->toSql()),
        );
        $this->assertStringContainsString(
            'study_vocab_variant_groups',
            implode(' ', $this->dropBlueprint($connection, 'study_vocab_variant_groups')->toSql()),
        );
    }

    public function test_migration_drops_sentences_before_their_parent_groups(): void
    {
        $contents = file_get_contents(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_18_190000_create_study_vocab_bundle_tables.php',
        );

        $this->assertIsString($contents);
        $sentenceDrop = strpos($contents, "Schema::dropIfExists('study_vocab_variant_sentences')");
        $groupDrop = strpos($contents, "Schema::dropIfExists('study_vocab_variant_groups')");
        $this->assertIsInt($sentenceDrop);
        $this->assertIsInt($groupDrop);
        $this->assertLessThan($groupDrop, $sentenceDrop);
    }

    public function test_postgres_sql_is_pinned_for_the_forward_compatible_cutover(): void
    {
        $connection = $this->connection(PostgresConnection::class);
        $connection->setSchemaGrammar(new PostgresGrammar($connection));

        $this->assertSame([
            'create table "study_vocab_variant_groups" ("id" char(26) not null, "user_id" bigint not null, "target_word" varchar(500) not null, "target_reading" varchar(1000) null, "target_meaning" varchar(1000) null, "source_sentence" text null, "source_context" text null, "include_learner_context" boolean not null default \'1\', "created_at" timestamp(0) without time zone null, "updated_at" timestamp(0) without time zone null)',
            'alter table "study_vocab_variant_groups" add constraint "study_vocab_variant_groups_user_id_foreign" foreign key ("user_id") references "users" ("id") on delete cascade',
            'create index "study_vocab_groups_user_created_id_idx" on "study_vocab_variant_groups" ("user_id", "created_at", "id")',
            'create index "study_vocab_groups_user_target_idx" on "study_vocab_variant_groups" ("user_id", "target_word")',
            'alter table "study_vocab_variant_groups" add primary key ("id")',
        ], $this->groupBlueprint($connection)->toSql());
        $this->assertSame([
            'create table "study_vocab_variant_sentences" ("id" char(26) not null, "user_id" bigint not null, "variant_group_id" char(26) not null, "ordinal" smallint not null, "sentence_jp" text not null, "sentence_reading" text null, "sentence_en" text not null, "notes" text null, "created_at" timestamp(0) without time zone null, "updated_at" timestamp(0) without time zone null)',
            'alter table "study_vocab_variant_sentences" add constraint "study_vocab_variant_sentences_user_id_foreign" foreign key ("user_id") references "users" ("id") on delete cascade',
            'alter table "study_vocab_variant_sentences" add constraint "study_vocab_variant_sentences_variant_group_id_foreign" foreign key ("variant_group_id") references "study_vocab_variant_groups" ("id") on delete cascade',
            'alter table "study_vocab_variant_sentences" add constraint "study_vocab_sentences_group_ordinal_unique" unique ("variant_group_id", "ordinal")',
            'create index "study_vocab_sentences_user_group_idx" on "study_vocab_variant_sentences" ("user_id", "variant_group_id")',
            'alter table "study_vocab_variant_sentences" add primary key ("id")',
        ], $this->sentenceBlueprint($connection)->toSql());
    }

    public function test_all_explicit_and_generated_identifiers_fit_postgres_limit(): void
    {
        foreach (self::IDENTIFIERS as $identifier) {
            $this->assertLessThanOrEqual(63, strlen($identifier), $identifier);
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

    private function groupBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_vocab_variant_groups', function (Blueprint $table): void {
            $table->create();
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('target_word', 500);
            $table->string('target_reading', 1000)->nullable();
            $table->string('target_meaning', 1000)->nullable();
            $table->text('source_sentence')->nullable();
            $table->text('source_context')->nullable();
            $table->boolean('include_learner_context')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'created_at', 'id'], 'study_vocab_groups_user_created_id_idx');
            $table->index(['user_id', 'target_word'], 'study_vocab_groups_user_target_idx');
        });
    }

    private function sentenceBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'study_vocab_variant_sentences', function (Blueprint $table): void {
            $table->create();
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('variant_group_id')->constrained('study_vocab_variant_groups')->cascadeOnDelete();
            $table->unsignedTinyInteger('ordinal');
            $table->text('sentence_jp');
            $table->text('sentence_reading')->nullable();
            $table->text('sentence_en');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['variant_group_id', 'ordinal'], 'study_vocab_sentences_group_ordinal_unique');
            $table->index(['user_id', 'variant_group_id'], 'study_vocab_sentences_user_group_idx');
        });
    }

    private function dropBlueprint(Connection $connection, string $tableName): Blueprint
    {
        return new Blueprint($connection, $tableName, function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }
}
