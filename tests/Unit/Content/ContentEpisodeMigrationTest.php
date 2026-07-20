<?php

namespace Tests\Unit\Content;

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

class ContentEpisodeMigrationTest extends TestCase
{
    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_20_220000_create_content_episode_tables.php',
        );
    }

    #[DataProvider('segmentSqlProvider')]
    public function test_complex_segment_schema_compiles_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $this->assertSame($expectedSql, $this->segmentBlueprint($connection)->toSql());
        $this->assertSame(
            [$connection instanceof MySqlConnection
                ? 'drop table if exists `content_audio_script_segments`'
                : 'drop table if exists "content_audio_script_segments"'],
            $this->dropSegmentBlueprint($connection)->toSql(),
        );
    }

    public function test_constraint_and_index_names_fit_postgres_identifier_limit(): void
    {
        $names = [
            'content_episodes_user_updated_id_idx',
            'content_episodes_user_type_idx',
            'content_episodes_user_status_idx',
            'content_episodes_user_id_foreign',
            'content_dialogues_episode_id_foreign',
            'content_dialogues_episode_id_unique',
            'content_speakers_dialogue_id_foreign',
            'content_speakers_dialogue_id_index',
            'content_sentences_dialogue_id_foreign',
            'content_sentences_speaker_id_foreign',
            'content_sentences_dialogue_order_id_idx',
            'content_images_episode_id_foreign',
            'content_images_episode_order_id_idx',
            'content_audio_scripts_episode_id_foreign',
            'content_audio_scripts_episode_id_unique',
            'content_audio_scripts_status_index',
            'content_audio_script_media_user_id_foreign',
            'content_audio_media_user_updated_id_idx',
            'content_audio_script_segments_script_id_foreign',
            'content_audio_script_segments_image_media_id_foreign',
            'content_audio_segments_script_order_unique',
            'content_audio_script_renders_script_id_foreign',
            'content_audio_renders_script_speed_unique',
            'content_episode_courses_episode_id_foreign',
            'content_episode_courses_course_episode_unique',
            'content_episode_courses_episode_id_index',
        ];

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(63, strlen($name), "Database identifier [{$name}] is too long for PostgreSQL.");
        }
    }

    /**
     * @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>}>
     */
    public static function segmentSqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                [
                    'create table "content_audio_script_segments" ("id" varchar not null, "script_id" varchar not null, "sort_order" integer not null, "text" text not null, "image_media_id" varchar, "image_generated_at" datetime, "metadata" text, "created_at" datetime, "updated_at" datetime, foreign key("script_id") references "content_audio_scripts"("id") on delete cascade, foreign key("image_media_id") references "content_audio_script_media"("id") on delete set null, primary key ("id"))',
                    'create unique index "content_audio_segments_script_order_unique" on "content_audio_script_segments" ("script_id", "sort_order")',
                ],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create table "content_audio_script_segments" ("id" uuid not null, "script_id" uuid not null, "sort_order" integer not null, "text" text not null, "image_media_id" uuid null, "image_generated_at" timestamp(0) with time zone null, "metadata" json null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null)',
                    'alter table "content_audio_script_segments" add constraint "content_audio_script_segments_script_id_foreign" foreign key ("script_id") references "content_audio_scripts" ("id") on delete cascade',
                    'alter table "content_audio_script_segments" add constraint "content_audio_script_segments_image_media_id_foreign" foreign key ("image_media_id") references "content_audio_script_media" ("id") on delete set null',
                    'alter table "content_audio_script_segments" add constraint "content_audio_segments_script_order_unique" unique ("script_id", "sort_order")',
                    'alter table "content_audio_script_segments" add primary key ("id")',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'create table `content_audio_script_segments` (`id` char(36) not null, `script_id` char(36) not null, `sort_order` int unsigned not null, `text` text not null, `image_media_id` char(36) null, `image_generated_at` timestamp null, `metadata` json null, `created_at` timestamp null, `updated_at` timestamp null, primary key (`id`))',
                    'alter table `content_audio_script_segments` add constraint `content_audio_script_segments_script_id_foreign` foreign key (`script_id`) references `content_audio_scripts` (`id`) on delete cascade',
                    'alter table `content_audio_script_segments` add constraint `content_audio_script_segments_image_media_id_foreign` foreign key (`image_media_id`) references `content_audio_script_media` (`id`) on delete set null',
                    'alter table `content_audio_script_segments` add unique `content_audio_segments_script_order_unique`(`script_id`, `sort_order`)',
                ],
            ],
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

    private function segmentBlueprint(Connection $connection): Blueprint
    {
        // Keep this representative complex table synchronized with the production migration.
        return new Blueprint($connection, 'content_audio_script_segments', function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->foreignUuid('script_id')->constrained('content_audio_scripts')->cascadeOnDelete();
            $table->unsignedInteger('sort_order');
            $table->text('text');
            $table->foreignUuid('image_media_id')->nullable()->constrained('content_audio_script_media')->nullOnDelete();
            $table->timestampTz('image_generated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['script_id', 'sort_order'], 'content_audio_segments_script_order_unique');
        });
    }

    private function dropSegmentBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'content_audio_script_segments', function (Blueprint $table): void {
            $table->dropIfExists();
        });
    }
}
