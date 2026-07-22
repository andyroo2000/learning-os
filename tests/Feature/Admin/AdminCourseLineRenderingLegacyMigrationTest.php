<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Models\AdminCourseLineRendering;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminCourseLineRenderingLegacyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_copies_matching_legacy_rows_without_mutating_the_legacy_table(): void
    {
        $course = $this->course();
        $orphanCourseId = (string) Str::uuid();
        $copiedId = (string) Str::uuid();
        $orphanId = (string) Str::uuid();
        $migration = require database_path(
            'migrations/2026_07_22_210000_create_admin_course_line_renderings_table.php',
        );
        $migration->down();
        Schema::create('line_audio_renderings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('courseId');
            $table->unsignedInteger('unitIndex');
            $table->text('text');
            $table->double('speed');
            $table->string('voiceId');
            $table->text('audioUrl');
            $table->timestampTz('createdAt', 3);
        });
        DB::table('line_audio_renderings')->insert([
            [
                'id' => $copiedId,
                'courseId' => $course->id,
                'unitIndex' => 2,
                'text' => 'Copied line',
                'speed' => 0.85,
                'voiceId' => 'fishaudio:0123456789abcdef0123456789abcdef',
                'audioUrl' => 'https://storage.example.test/copied.mp3',
                'createdAt' => '2026-07-22 17:00:00.500',
            ],
            [
                'id' => $orphanId,
                'courseId' => $orphanCourseId,
                'unitIndex' => 3,
                'text' => 'Orphan line',
                'speed' => 1,
                'voiceId' => 'fishaudio:0123456789abcdef0123456789abcdef',
                'audioUrl' => 'https://storage.example.test/orphan.mp3',
                'createdAt' => '2026-07-22 17:00:01.500',
            ],
        ]);

        $migration->up();

        $copied = AdminCourseLineRendering::query()->sole();
        $this->assertSame($copiedId, $copied->id);
        $this->assertSame($course->id, $copied->course_id);
        $this->assertSame('https://storage.example.test/copied.mp3', $copied->audio_url);
        $this->assertNull($copied->audio_storage_path);
        $this->assertDatabaseCount('line_audio_renderings', 2);
        $this->assertDatabaseMissing('admin_course_line_renderings', ['id' => $orphanId]);
    }

    private function course(): ContentCourse
    {
        $user = User::factory()->create();

        return ContentCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => (string) Str::uuid(),
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Legacy rendering course',
            'status' => 'draft',
            'is_sample_content' => false,
            'is_test_course' => true,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 15,
            'l1_voice_id' => 'fishaudio:narrator',
            'speaker1_gender' => 'male',
            'speaker2_gender' => 'female',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
