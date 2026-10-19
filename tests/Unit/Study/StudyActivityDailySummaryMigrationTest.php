<?php

namespace Tests\Unit\Study;

use App\Models\User;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class StudyActivityDailySummaryMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'study_activity_daily_summaries';

    #[DataProvider('databases')]
    public function test_actual_migration_compiles_creation_and_rollback_for_each_database(
        string $connectionClass,
        string $grammarClass,
        string $timestampType,
    ): void {
        $connection = new $connectionClass(new PDO('sqlite::memory:'), 'testing');
        $connection->setSchemaGrammar(new $grammarClass($connection));
        $creation = [];
        Schema::shouldReceive('create')->once()->with(self::TABLE, \Mockery::type('callable'))
            ->andReturnUsing(function ($name, $callback) use ($connection, &$creation): void {
                $blueprint = new Blueprint($connection, $name, function (Blueprint $table) use ($callback): void {
                    $table->create();
                    $callback($table);
                });
                $creation = $blueprint->toSql();
            });
        $rollback = [];
        Schema::shouldReceive('dropIfExists')->once()->with(self::TABLE)
            ->andReturnUsing(function ($name) use ($connection, &$rollback): void {
                $blueprint = new Blueprint($connection, $name);
                $blueprint->dropIfExists();
                $rollback = $blueprint->toSql();
            });

        $migration = require $this->migrationPath();
        $migration->up();
        $migration->down();

        $sql = str_replace(['"', '`'], '', implode(' ', $creation));
        $this->assertStringContainsString('study_activity_day_unique', $sql);
        $this->assertStringContainsString('(user_id, timezone, algorithm_version, local_date)', $sql);
        $this->assertStringContainsString('study_activity_day_end_index', $sql);
        $this->assertStringContainsString('(user_id, ended_at)', $sql);
        $this->assertStringContainsString('references users', $sql);
        $this->assertStringContainsString('on delete cascade', $sql);
        $this->assertStringContainsString('started_at '.$timestampType, $sql);
        $this->assertStringContainsString('ended_at '.$timestampType, $sql);
        $this->assertStringContainsString('created_at '.$timestampType, $sql);
        $this->assertStringContainsString('updated_at '.$timestampType, $sql);
        $this->assertSame(['drop table if exists '.self::TABLE], array_map(
            static fn (string $statement): string => str_replace(['"', '`'], '', $statement),
            $rollback,
        ));
    }

    public function test_keys_separate_owners_timezones_versions_and_dates_and_owner_deletion_cascades(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $base = $this->row($owner->id);
        DB::table(self::TABLE)->insert([
            $base,
            array_replace($base, ['timezone' => 'Asia/Tokyo']),
            array_replace($base, ['algorithm_version' => 2]),
            array_replace($base, ['local_date' => '2026-10-17']),
            $this->row($other->id),
        ]);

        $this->assertDatabaseCount(self::TABLE, 5);
        $owner->delete();

        $this->assertDatabaseCount(self::TABLE, 1);
        $this->assertDatabaseHas(self::TABLE, ['user_id' => $other->id]);
    }

    public function test_same_owner_timezone_version_and_day_cannot_be_inserted_twice(): void
    {
        $row = $this->row(User::factory()->create()->id);
        DB::table(self::TABLE)->insert($row);

        $this->expectException(QueryException::class);
        DB::table(self::TABLE)->insert($row);
    }

    public function test_summary_requires_an_existing_owner(): void
    {
        $this->expectException(QueryException::class);
        DB::table(self::TABLE)->insert($this->row(999_999_999));
    }

    public function test_migration_rolls_back_and_can_be_reapplied_without_backfilling_history(): void
    {
        $this->assertDatabaseCount(self::TABLE, 0);
        $migration = require $this->migrationPath();
        $migration->down();
        $this->assertFalse(Schema::hasTable(self::TABLE));
        $migration->up();
        $this->assertTrue(Schema::hasTable(self::TABLE));
        $this->assertDatabaseCount(self::TABLE, 0);
    }

    public static function databases(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class, SQLiteGrammar::class, 'datetime'],
            'postgres' => [PostgresConnection::class, PostgresGrammar::class, 'timestamp(6) with time zone'],
            'mysql' => [MySqlConnection::class, MySqlGrammar::class, 'timestamp(6)'],
        ];
    }

    private function migrationPath(): string
    {
        return base_path('database/migrations/2026_10_19_160000_create_study_activity_daily_summaries.php');
    }

    private function row(int $userId): array
    {
        return [
            'user_id' => $userId,
            'timezone' => 'UTC',
            'algorithm_version' => 1,
            'local_date' => '2026-10-18',
            'started_at' => '2026-10-18 00:00:00.000000',
            'ended_at' => '2026-10-19 00:00:00.000000',
            'categories' => json_encode([
                'review' => 0, 'listen' => 0, 'create' => 0,
                'immerse' => 0, 'conversation' => 0, 'wanikani' => 0,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
