<?php

namespace Tests\Feature\Retirement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetireCreatorStorageTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'content_generation_requests', 'content_audio_script_generation_jobs',
        'content_image_generation_jobs', 'content_audio_generation_jobs',
        'content_dialogue_generation_jobs', 'admin_script_lab_audio_renderings',
        'admin_sentence_script_tests',
    ];

    public function test_retirement_removes_only_retired_schema_and_experiment_audio(): void
    {
        $user = User::factory()->create();
        $disk = Storage::fake('media');
        $disk->put('admin-script-lab-audio/actor/render.mp3', 'retired');
        $disk->put('episodes/saved.mp3', 'saved');
        $migration = require database_path('migrations/2026_10_17_180000_retire_creator_storage.php');
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertFalse(Schema::hasColumn('feature_flags', 'scriptsEnabled'));
        $migration->down();
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertTrue(Schema::hasIndex('admin_sentence_script_tests', 'admin_sentence_script_tests_actor_order_idx'));
        $this->assertTrue(Schema::hasIndex('content_generation_requests', 'content_gen_requests_retention_idx'));
        $migration->up();
        $disk->assertMissing('admin-script-lab-audio/actor/render.mp3');
        $disk->assertExists('episodes/saved.mp3');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        foreach (['content_episodes', 'content_dialogues', 'content_audio_scripts', 'admin_course_line_renderings'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        foreach (['dialoguesEnabled', 'scriptsEnabled', 'audioCourseEnabled'] as $column) {
            $this->assertFalse(Schema::hasColumn('feature_flags', $column));
        }
    }
}
