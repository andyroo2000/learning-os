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

class StudyMilestoneMigrationTest extends TestCase
{
    private const USER_KEY_UNIQUE = 'study_milestones_user_key_unique';

    private const USER_EARNED_INDEX = 'study_milestones_user_earned_idx';

    private const USER_PENDING_INDEX = 'study_milestones_user_pending_idx';

    #[DataProvider('sqlProvider')]
    public function test_milestone_tables_compile_to_portable_create_and_drop_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedProfileCreate,
        array $expectedMilestoneCreate,
        array $expectedMilestoneDrop,
        array $expectedProfileDrop,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $this->assertSame($expectedProfileCreate, $this->profileBlueprint($connection)->toSql());
        $this->assertSame($expectedMilestoneCreate, $this->milestoneBlueprint($connection)->toSql());
        $this->assertSame($expectedMilestoneDrop, $this->dropBlueprint($connection, 'study_milestones')->toSql());
        $this->assertSame($expectedProfileDrop, $this->dropBlueprint($connection, 'study_milestone_profiles')->toSql());
    }

    public function test_index_names_fit_postgres_identifier_limit(): void
    {
        foreach ([self::USER_KEY_UNIQUE, self::USER_EARNED_INDEX, self::USER_PENDING_INDEX] as $name) {
            $this->assertLessThanOrEqual(63, strlen($name), "Index name [{$name}] exceeds PostgreSQL's identifier limit.");
        }
    }

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>, list<string>, list<string>}> */
    public static function sqlProvider(): array
    {
        return [
            'sqlite' => [
                SQLiteConnection::class,
                SQLiteGrammar::class,
                ['create table "study_milestone_profiles" ("user_id" integer not null, "initialized_at" datetime not null, foreign key("user_id") references "users"("id") on delete cascade, primary key ("user_id"))'],
                [
                    'create table "study_milestones" ("id" integer primary key autoincrement not null, "user_id" integer not null, "milestone_key" varchar not null, "earned_at" datetime not null, "presented_at" datetime, "created_at" datetime, "updated_at" datetime, foreign key("user_id") references "users"("id") on delete cascade)',
                    'create unique index "study_milestones_user_key_unique" on "study_milestones" ("user_id", "milestone_key")',
                    'create index "study_milestones_user_earned_idx" on "study_milestones" ("user_id", "earned_at", "id")',
                    'create index "study_milestones_user_pending_idx" on "study_milestones" ("user_id", "presented_at", "earned_at", "id")',
                ],
                ['drop table "study_milestones"'],
                ['drop table "study_milestone_profiles"'],
            ],
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'create table "study_milestone_profiles" ("user_id" bigint not null, "initialized_at" timestamp(6) with time zone not null)',
                    'alter table "study_milestone_profiles" add constraint "study_milestone_profiles_user_id_foreign" foreign key ("user_id") references "users" ("id") on delete cascade',
                    'alter table "study_milestone_profiles" add primary key ("user_id")',
                ],
                [
                    'create table "study_milestones" ("id" bigserial not null primary key, "user_id" bigint not null, "milestone_key" varchar(64) not null, "earned_at" timestamp(6) with time zone not null, "presented_at" timestamp(6) with time zone null, "created_at" timestamp(6) with time zone null, "updated_at" timestamp(6) with time zone null)',
                    'alter table "study_milestones" add constraint "study_milestones_user_id_foreign" foreign key ("user_id") references "users" ("id") on delete cascade',
                    'alter table "study_milestones" add constraint "study_milestones_user_key_unique" unique ("user_id", "milestone_key")',
                    'create index "study_milestones_user_earned_idx" on "study_milestones" ("user_id", "earned_at", "id")',
                    'create index "study_milestones_user_pending_idx" on "study_milestones" ("user_id", "presented_at", "earned_at", "id")',
                ],
                ['drop table "study_milestones"'],
                ['drop table "study_milestone_profiles"'],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'create table `study_milestone_profiles` (`user_id` bigint unsigned not null, `initialized_at` timestamp(6) not null, primary key (`user_id`))',
                    'alter table `study_milestone_profiles` add constraint `study_milestone_profiles_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade',
                ],
                [
                    'create table `study_milestones` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null, `milestone_key` varchar(64) not null, `earned_at` timestamp(6) not null, `presented_at` timestamp(6) null, `created_at` timestamp(6) null, `updated_at` timestamp(6) null)',
                    'alter table `study_milestones` add constraint `study_milestones_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade',
                    'alter table `study_milestones` add unique `study_milestones_user_key_unique`(`user_id`, `milestone_key`)',
                    'alter table `study_milestones` add index `study_milestones_user_earned_idx`(`user_id`, `earned_at`, `id`)',
                    'alter table `study_milestones` add index `study_milestones_user_pending_idx`(`user_id`, `presented_at`, `earned_at`, `id`)',
                ],
                ['drop table `study_milestones`'],
                ['drop table `study_milestone_profiles`'],
            ],
        ];
    }

    private function profileBlueprint(Connection $connection): Blueprint
    {
        // Mirrors the migration; update this compile-only portability fixture with its schema.
        return new Blueprint($connection, 'study_milestone_profiles', function (Blueprint $table): void {
            $table->create();
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->timestampTz('initialized_at', 6);
        });
    }

    private function milestoneBlueprint(Connection $connection): Blueprint
    {
        // Mirrors the migration; update this compile-only portability fixture with its schema.
        return new Blueprint($connection, 'study_milestones', function (Blueprint $table): void {
            $table->create();
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('milestone_key', 64);
            $table->timestampTz('earned_at', 6);
            $table->timestampTz('presented_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->unique(['user_id', 'milestone_key'], self::USER_KEY_UNIQUE);
            $table->index(['user_id', 'earned_at', 'id'], self::USER_EARNED_INDEX);
            $table->index(['user_id', 'presented_at', 'earned_at', 'id'], self::USER_PENDING_INDEX);
        });
    }

    private function dropBlueprint(Connection $connection, string $tableName): Blueprint
    {
        return new Blueprint($connection, $tableName, static fn (Blueprint $table) => $table->drop());
    }

    /** @param class-string<Connection> $connectionClass */
    private function connection(string $connectionClass): Connection
    {
        return new $connectionClass(new PDO('sqlite::memory:'));
    }
}
