<?php

namespace Tests\Feature\Retirement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CancelRetiredContentGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_retirement_cancels_only_the_removed_job_classes(): void
    {
        $storage = require database_path('migrations/2026_10_17_180000_retire_creator_storage.php');
        $storage->down();
        $retired = $this->enqueue('App\\Jobs\\ProcessContentDialogueGeneration');
        $active = $this->enqueue('App\\Jobs\\ProcessStudyCardDraft');
        $unknown = $this->enqueue('App\\Jobs\\ProcessContentSomethingElse');
        $migration = require database_path('migrations/2026_10_17_170000_cancel_retired_content_generation.php');
        $migration->up();
        $this->assertDatabaseMissing('jobs', ['id' => $retired]);
        $this->assertDatabaseHas('jobs', ['id' => $active]);
        $this->assertDatabaseHas('jobs', ['id' => $unknown]);
        $migration->down();
        $this->assertDatabaseMissing('jobs', ['id' => $retired]);
    }

    private function enqueue(string $name): int
    {
        return DB::table('jobs')->insertGetId([
            'queue' => 'default', 'payload' => json_encode(['displayName' => $name]),
            'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
        ]);
    }
}
