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
        $connection->setSchemaGrammar(new $grammarClass($connection));

        $upSql = implode("\n", [
            ...$this->addUserSourceIdBlueprint($connection)->toSql(),
            ...$this->userProjectionBlueprint($connection)->toSql(),
            ...$this->inviteCodeBlueprint($connection)->toSql(),
        ]);
        $downSql = implode("\n", [
            ...$this->dropTableBlueprint($connection, 'admin_invite_codes')->toSql(),
            ...$this->dropTableBlueprint($connection, 'admin_user_projections')->toSql(),
            ...$this->dropUserSourceIdBlueprint($connection)->toSql(),
        ]);

        foreach ([
            'admin_user_projections',
            'convolab_id',
            'user_id',
            'display_name',
            'avatar_color',
            'preferred_study_language',
            'preferred_native_language',
            'onboarding_completed',
            'admin_invite_codes',
            'convolab_used_by',
            'users_convolab_id_unique',
            'admin_users_created_convolab_id_idx',
            'admin_invites_created_id_idx',
            'admin_invites_convolab_user_idx',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $upSql);
        }

        $this->assertStringContainsString('admin_invite_codes', $downSql);
        $this->assertStringContainsString('admin_user_projections', $downSql);
        $this->assertStringContainsString('users_convolab_id_unique', $downSql);
        $this->assertStringContainsString('convolab_id', $downSql);
        if ($connectionClass !== SQLiteConnection::class) {
            $this->assertStringContainsString('timestamp(3)', $upSql);
        }
    }

    public function test_constraint_and_index_names_fit_the_postgres_identifier_limit(): void
    {
        foreach ([
            'users_convolab_id_unique',
            'admin_user_projections_pkey',
            'admin_user_projections_user_id_unique',
            'admin_user_projections_user_id_foreign',
            'admin_users_created_convolab_id_idx',
            'admin_invite_codes_code_unique',
            'admin_invite_codes_used_by_foreign',
            'admin_invites_created_id_idx',
            'admin_invites_convolab_user_idx',
        ] as $name) {
            $this->assertLessThanOrEqual(63, strlen($name), "Database identifier [{$name}] is too long.");
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

    private function addUserSourceIdBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'users', function (Blueprint $table): void {
            $table->uuid('convolab_id')->nullable()->unique('users_convolab_id_unique');
        });
    }

    private function userProjectionBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'admin_user_projections', function (Blueprint $table): void {
            $table->create();
            $table->uuid('convolab_id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('avatar_color', 32)->nullable();
            $table->text('avatar_url')->nullable();
            $table->string('role', 32)->default('user');
            $table->string('preferred_study_language', 16)->default('ja');
            $table->string('preferred_native_language', 16)->default('en');
            $table->boolean('onboarding_completed')->default(false);
            $table->timestampTz('created_at', 3);
            $table->timestampTz('updated_at', 3);
            $table->index(['created_at', 'convolab_id'], 'admin_users_created_convolab_id_idx');
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

    private function dropTableBlueprint(Connection $connection, string $tableName): Blueprint
    {
        return new Blueprint($connection, $tableName, function (Blueprint $table): void {
            $table->drop();
        });
    }

    private function dropUserSourceIdBlueprint(Connection $connection): Blueprint
    {
        return new Blueprint($connection, 'users', function (Blueprint $table): void {
            $table->dropUnique('users_convolab_id_unique');
            $table->dropColumn('convolab_id');
        });
    }
}
