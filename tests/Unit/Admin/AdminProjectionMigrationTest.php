<?php

namespace Tests\Unit\Admin;

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

class AdminProjectionMigrationTest extends TestCase
{
    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            LEARNING_OS_PROJECT_ROOT.'/database/migrations/2026_07_21_170000_add_convolab_admin_projection.php',
        );
    }

    #[DataProvider('grammarProvider')]
    public function test_projection_schema_and_rollback_compile_for_supported_databases(
        string $connectionClass,
        string $grammarClass,
    ): void {
        $connection = $this->connection($connectionClass);
        $grammar = new $grammarClass($connection);
        $connection->setSchemaGrammar($grammar);

        $upSql = implode("\n", [
            ...$this->addUserProjectionBlueprint($connection)->toSql(),
            ...$this->inviteCodeBlueprint($connection)->toSql(),
        ]);
        $downSql = implode("\n", [
            ...$this->dropInviteCodeBlueprint($connection)->toSql(),
            ...$this->dropUserProjectionBlueprint($connection)->toSql(),
        ]);

        foreach ([
            'convolab_id',
            'convolab_admin_visible',
            'display_name',
            'avatar_color',
            'avatar_url',
            'preferred_study_language',
            'preferred_native_language',
            'onboarding_completed',
            'admin_invite_codes',
            'convolab_used_by',
            'users_convolab_id_unique',
            'users_convolab_visible_created_id_idx',
            'admin_invites_created_id_idx',
            'admin_invites_convolab_user_idx',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $upSql);
        }

        $this->assertStringContainsString('admin_invite_codes', $downSql);
        $this->assertStringContainsString('users_convolab_id_unique', $downSql);
        $this->assertStringContainsString('users_convolab_visible_created_id_idx', $downSql);
        $this->assertStringContainsString('convolab_id', $downSql);
        if ($connectionClass !== SQLiteConnection::class) {
            $this->assertStringContainsString('timestamp(3)', $upSql);
        }
    }

    public function test_constraint_and_index_names_fit_the_postgres_identifier_limit(): void
    {
        foreach ([
            'users_convolab_id_unique',
            'users_convolab_visible_created_id_idx',
            'admin_invite_codes_code_unique',
            'admin_invite_codes_used_by_foreign',
            'admin_invites_created_id_idx',
            'admin_invites_convolab_user_idx',
        ] as $name) {
            $this->assertLessThanOrEqual(63, strlen($name), "Database identifier [{$name}] is too long.");
        }
    }

    #[DataProvider('precisionSqlProvider')]
    public function test_user_timestamp_precision_and_rollback_compile_to_portable_sql(
        string $connectionClass,
        string $grammarClass,
        array $expectedUpSql,
        array $expectedDownSql,
    ): void {
        $connection = $this->connection($connectionClass);
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $this->assertSame($expectedUpSql, $this->userTimestampBlueprint($connection, 3)->toSql());
        $this->assertSame($expectedDownSql, $this->userTimestampBlueprint($connection, 0)->toSql());
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

    /** @return array<string, array{class-string<Connection>, class-string<Grammar>, list<string>, list<string>}> */
    public static function precisionSqlProvider(): array
    {
        return [
            'postgres' => [
                PostgresConnection::class,
                PostgresGrammar::class,
                [
                    'alter table "users" alter column "email_verified_at" type timestamp(3) without time zone, alter column "email_verified_at" drop not null, alter column "email_verified_at" drop default, alter column "email_verified_at" drop identity if exists',
                    'alter table "users" alter column "created_at" type timestamp(3) without time zone, alter column "created_at" drop not null, alter column "created_at" drop default, alter column "created_at" drop identity if exists',
                    'alter table "users" alter column "updated_at" type timestamp(3) without time zone, alter column "updated_at" drop not null, alter column "updated_at" drop default, alter column "updated_at" drop identity if exists',
                    'comment on column "users"."email_verified_at" is NULL',
                    'comment on column "users"."created_at" is NULL',
                    'comment on column "users"."updated_at" is NULL',
                ],
                [
                    'alter table "users" alter column "email_verified_at" type timestamp(0) without time zone, alter column "email_verified_at" drop not null, alter column "email_verified_at" drop default, alter column "email_verified_at" drop identity if exists',
                    'alter table "users" alter column "created_at" type timestamp(0) without time zone, alter column "created_at" drop not null, alter column "created_at" drop default, alter column "created_at" drop identity if exists',
                    'alter table "users" alter column "updated_at" type timestamp(0) without time zone, alter column "updated_at" drop not null, alter column "updated_at" drop default, alter column "updated_at" drop identity if exists',
                    'comment on column "users"."email_verified_at" is NULL',
                    'comment on column "users"."created_at" is NULL',
                    'comment on column "users"."updated_at" is NULL',
                ],
            ],
            'mysql' => [
                MySqlConnection::class,
                MySqlGrammar::class,
                [
                    'alter table `users` modify `email_verified_at` timestamp(3) null',
                    'alter table `users` modify `created_at` timestamp(3) null',
                    'alter table `users` modify `updated_at` timestamp(3) null',
                ],
                [
                    'alter table `users` modify `email_verified_at` timestamp null',
                    'alter table `users` modify `created_at` timestamp null',
                    'alter table `users` modify `updated_at` timestamp null',
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

    private function addUserProjectionBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'users', function (Blueprint $table): void {
            $table->uuid('convolab_id')->nullable()->unique('users_convolab_id_unique');
            $table->boolean('convolab_admin_visible')->default(false);
            $table->string('display_name')->nullable();
            $table->string('avatar_color', 32)->nullable();
            $table->text('avatar_url')->nullable();
            $table->string('role', 32)->default('user');
            $table->string('preferred_study_language', 16)->default('ja');
            $table->string('preferred_native_language', 16)->default('en');
            $table->boolean('onboarding_completed')->default(false);
            $table->index(
                ['convolab_admin_visible', 'created_at', 'id'],
                'users_convolab_visible_created_id_idx',
            );
        });
    }

    private function inviteCodeBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'admin_invite_codes', function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('convolab_used_by')->nullable();
            $table->timestampTz('used_at', 3)->nullable();
            $table->timestampTz('created_at', 3);
            $table->index(['created_at', 'id'], 'admin_invites_created_id_idx');
            $table->index('convolab_used_by', 'admin_invites_convolab_user_idx');
        });
    }

    private function dropInviteCodeBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'admin_invite_codes', function (Blueprint $table): void {
            $table->drop();
        });
    }

    private function dropUserProjectionBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'users', function (Blueprint $table): void {
            $table->dropIndex('users_convolab_visible_created_id_idx');
            $table->dropUnique('users_convolab_id_unique');
            $table->dropColumn([
                'convolab_id',
                'convolab_admin_visible',
                'display_name',
                'avatar_color',
                'avatar_url',
                'role',
                'preferred_study_language',
                'preferred_native_language',
                'onboarding_completed',
            ]);
        });
    }

    private function userTimestampBlueprint(Connection $connection, int $precision): Blueprint
    {
        return new Blueprint($connection, 'users', function (Blueprint $table) use ($precision): void {
            $table->timestamp('email_verified_at', $precision)->nullable()->change();
            $table->timestamp('created_at', $precision)->nullable()->change();
            $table->timestamp('updated_at', $precision)->nullable()->change();
        });
    }
}
