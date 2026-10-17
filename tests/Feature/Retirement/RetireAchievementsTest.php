<?php

namespace Tests\Feature\Retirement;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RetireAchievementsTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'achievement_awards', 'achievement_progress_projections',
        'achievement_card_projections', 'achievement_study_session_projections',
        'study_milestones', 'study_milestone_profiles',
    ];

    public function test_award_tables_are_removed_and_rollback_restores_empty_schema(): void
    {
        $user = User::factory()->create();
        $migration = $this->migration();
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertProjectionIndexes(false);
        $migration->down();
        $this->assertProjectionIndexes(true);
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertDatabaseCount($table, 0);
        }
        $migration->up();
        $this->assertProjectionIndexes(false);
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_retired_routes_are_not_registered(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri());
        foreach (['api/achievements/catalog', 'api/achievements/progress',
            'api/achievements/evaluate', 'api/study/milestones/evaluate',
            'api/study/milestones/present'] as $uri) {
            $this->assertNotContains($uri, $routes);
        }
    }

    private function assertProjectionIndexes(bool $expected): void
    {
        foreach ([
            'study_activity_sessions' => 'study_sessions_user_updated_id_idx',
            'cards' => 'cards_deck_updated_id_idx',
            'card_review_events' => 'card_review_events_created_at_id_idx',
        ] as $table => $index) {
            $this->assertSame($expected, Schema::hasIndex($table, $index), $index);
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_17_160000_retire_achievement_tables.php');
    }
}
