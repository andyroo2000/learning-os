<?php

namespace Tests\Feature\Learn;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearnFlagMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_flags_stay_unchanged_and_learn_starts_off_after_upgrade(): void
    {
        $migration = require database_path('migrations/2026_11_27_200000_add_learn_feature_flag.php');
        $migration->down();
        DB::table('feature_flags')->insert([
            'id' => 'legacy', 'flashcardsEnabled' => true, 'readingsEnabled' => true,
            'studyTimeEnabled' => false, 'updatedAt' => now(),
        ]);
        $migration->up();
        $this->assertDatabaseHas('feature_flags', [
            'id' => 'legacy', 'flashcardsEnabled' => true, 'readingsEnabled' => true,
            'studyTimeEnabled' => false, 'learnEnabled' => false,
        ]);
    }
}
