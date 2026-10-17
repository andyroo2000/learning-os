<?php

namespace Tests\Feature\Retirement;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RetireAchievementsGrammarTest extends TestCase
{
    /** @param class-string<Connection> $connectionClass */
    #[DataProvider('databaseConnections')]
    public function test_actual_migration_compiles_removal_and_rollback(string $connectionClass): void
    {
        // SQLite PDO is only a grammar host; pretend never executes foreign SQL.
        $connection = new $connectionClass(new PDO('sqlite::memory:'), 'testing');
        $connection->useDefaultSchemaGrammar();
        $original = Schema::getFacadeRoot();
        Schema::swap($connection->getSchemaBuilder());
        $migration = require database_path('migrations/2026_10_17_160000_retire_achievement_tables.php');
        try {
            $drop = $connection->pretend(fn () => $migration->up());
            $create = $connection->pretend(fn () => $migration->down());
        } finally {
            Schema::swap($original);
        }
        $dropSql = strtolower(implode("\n", array_column($drop, 'query')));
        $createSql = strtolower(implode("\n", array_column($create, 'query')));
        foreach (['achievement_awards', 'achievement_progress_projections',
            'achievement_card_projections', 'achievement_study_session_projections',
            'study_milestones', 'study_milestone_profiles'] as $table) {
            $this->assertMatchesRegularExpression('/drop table if exists ["`]?'.$table.'["`]?/', $dropSql);
            $this->assertMatchesRegularExpression('/create table ["`]?'.$table.'["`]?/', $createSql);
        }
        foreach (['card_review_events_created_at_id_idx', 'cards_deck_updated_id_idx',
            'study_sessions_user_updated_id_idx'] as $index) {
            $this->assertStringContainsString($index, $dropSql);
            $this->assertStringContainsString($index, $createSql);
        }
    }

    /** @return array<string, array{class-string<Connection>}> */
    public static function databaseConnections(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class],
            'mysql' => [MySqlConnection::class],
            'postgres' => [PostgresConnection::class],
        ];
    }
}
