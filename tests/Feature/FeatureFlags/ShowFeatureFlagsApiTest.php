<?php

namespace Tests\Feature\FeatureFlags;

use App\Domain\FeatureFlags\Models\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ShowFeatureFlagsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/feature-flags')->assertUnauthorized();
    }

    public function test_show_returns_the_existing_legacy_contract_unwrapped(): void
    {
        $this->signIn();
        Carbon::setTestNow('2026-07-20 17:15:12.345 UTC');

        $featureFlags = new FeatureFlag([
            'dialoguesEnabled' => false,
            'scriptsEnabled' => true,
            'audioCourseEnabled' => false,
            'flashcardsEnabled' => true,
        ]);
        $featureFlags->id = 'existing';
        $featureFlags->save();

        $this->getJson('/api/feature-flags')
            ->assertOk()
            ->assertExactJson([
                'id' => 'existing',
                'dialoguesEnabled' => false,
                'scriptsEnabled' => true,
                'audioCourseEnabled' => false,
                'flashcardsEnabled' => true,
                'updatedAt' => '2026-07-20T17:15:12.345Z',
            ]);
    }

    public function test_show_materializes_enabled_defaults_when_the_row_is_missing(): void
    {
        $this->signIn();
        Carbon::setTestNow('2026-07-20 17:15:12.345 UTC');

        $this->getJson('/api/feature-flags')
            ->assertOk()
            ->assertExactJson([
                'id' => FeatureFlag::DEFAULT_ID,
                'dialoguesEnabled' => true,
                'scriptsEnabled' => true,
                'audioCourseEnabled' => true,
                'flashcardsEnabled' => true,
                'updatedAt' => '2026-07-20T17:15:12.345Z',
            ]);

        $this->assertDatabaseHas('feature_flags', [
            'id' => FeatureFlag::DEFAULT_ID,
            'dialoguesEnabled' => true,
            'scriptsEnabled' => true,
            'audioCourseEnabled' => true,
            'flashcardsEnabled' => true,
        ]);
    }

    public function test_show_returns_the_winning_defaults_from_a_concurrent_first_read(): void
    {
        $this->signIn();
        Carbon::setTestNow('2026-07-20 17:15:12.345 UTC');
        $eventName = 'eloquent.creating: '.FeatureFlag::class;

        Event::listen($eventName, static function (FeatureFlag $featureFlags): void {
            DB::table('feature_flags')->insert([
                'id' => $featureFlags->getKey(),
                'dialoguesEnabled' => false,
                'scriptsEnabled' => true,
                'audioCourseEnabled' => false,
                'flashcardsEnabled' => true,
                'updatedAt' => now()->format('Y-m-d H:i:s.v'),
            ]);
        });

        try {
            $this->getJson('/api/feature-flags')
                ->assertOk()
                ->assertExactJson([
                    'id' => FeatureFlag::DEFAULT_ID,
                    'dialoguesEnabled' => false,
                    'scriptsEnabled' => true,
                    'audioCourseEnabled' => false,
                    'flashcardsEnabled' => true,
                    'updatedAt' => '2026-07-20T17:15:12.345Z',
                ]);
        } finally {
            // This model has no production creating listeners; clear the injected test event.
            Event::forget($eventName);
        }

        $this->assertDatabaseCount('feature_flags', 1);
    }

    public function test_show_existing_flags_uses_one_feature_flag_query(): void
    {
        $this->signIn();
        $featureFlags = new FeatureFlag([
            'dialoguesEnabled' => true,
            'scriptsEnabled' => true,
            'audioCourseEnabled' => true,
            'flashcardsEnabled' => true,
        ]);
        $featureFlags->id = FeatureFlag::DEFAULT_ID;
        $featureFlags->save();

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->getJson('/api/feature-flags')->assertOk();
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $featureFlagQueries = $queries->filter(
            fn (array $query): bool => str_contains($query['query'], 'feature_flags'),
        );

        $this->assertCount(1, $featureFlagQueries, $queries->pluck('query')->implode("\n"));
    }

    public function test_adoption_migration_preserves_a_preexisting_feature_flags_table(): void
    {
        $featureFlags = new FeatureFlag([
            'dialoguesEnabled' => false,
            'scriptsEnabled' => false,
            'audioCourseEnabled' => true,
            'flashcardsEnabled' => true,
        ]);
        $featureFlags->id = 'legacy';
        $featureFlags->save();

        $migration = require database_path('migrations/2026_07_20_170000_adopt_feature_flags_table.php');

        $migration->up();
        $migration->down();

        $this->assertDatabaseHas('feature_flags', [
            'id' => 'legacy',
            'dialoguesEnabled' => false,
            'scriptsEnabled' => false,
            'audioCourseEnabled' => true,
            'flashcardsEnabled' => true,
        ]);
    }
}
