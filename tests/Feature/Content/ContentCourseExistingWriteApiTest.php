<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentCourseExistingWriteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_uuid_course_cannot_claim_a_legacy_null_fingerprint_row(): void
    {
        $user = User::factory()->create();
        $course = $this->courseFor($user);

        $this->asConvoLabBrowser($user, convoLabUserId: $course->convolab_user_id)
            ->postJson('/api/convolab/courses', [
                'id' => $course->id,
                'title' => 'New Course',
                'description' => 'No provider.',
                'nativeLanguage' => 'en',
                'targetLanguage' => 'ja',
                'sourceText' => 'A new inline dialogue.',
            ])
            ->assertConflict()
            ->assertExactJson([
                'code' => 'idempotency_conflict',
                'message' => 'Creation ID was already used for different content.',
            ]);

        $this->assertDatabaseCount('content_courses', 1);
    }

    public function test_browser_session_updates_only_supplied_course_fields_and_hides_other_owners(): void
    {
        $user = User::factory()->create();
        $owned = $this->courseFor($user, [
            'title' => 'Original',
            'description' => 'Keep this.',
            'max_lesson_duration_minutes' => 30,
        ]);
        $other = $this->courseFor($user, ['title' => 'Other']);
        $this->asConvoLabBrowser($user, convoLabUserId: strtoupper($owned->convolab_user_id))
            ->withoutMiddleware(TrimStrings::class)
            ->patchJson('/api/convolab/courses/'.strtoupper($owned->id), [
                'title' => '  Updated  ',
                'maxLessonDurationMinutes' => 45,
            ])
            ->assertOk()
            ->assertExactJson(['message' => 'Course updated successfully']);

        $owned->refresh();
        $this->assertSame('Updated', $owned->title);
        $this->assertSame('Keep this.', $owned->description);
        $this->assertSame(45, $owned->max_lesson_duration_minutes);
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $owned->source_system);

        $this->asConvoLabBrowser($user, convoLabUserId: $owned->convolab_user_id)
            ->patchJson('/api/convolab/courses/'.$other->id, ['title' => 'Hidden'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Course not found']);
        $this->assertSame('Other', $other->fresh()->title);
    }

    public function test_empty_update_promotes_ownership_and_preserves_legacy_touch_behavior(): void
    {
        $user = User::factory()->create();
        $course = $this->courseFor($user);
        $originalUpdatedAt = $course->updated_at;

        $this->travel(1)->second();
        try {
            $this->asConvoLabBrowser($user, convoLabUserId: $course->convolab_user_id)
                ->patchJson('/api/convolab/courses/'.$course->id, [])
                ->assertOk();
        } finally {
            $this->travelBack();
        }

        $course->refresh();
        $this->assertTrue($course->updated_at->isAfter($originalUpdatedAt));
        $this->assertSame(ContentSourceSystem::LEARNING_OS, $course->source_system);
    }

    public function test_course_update_validates_provenance_and_mutable_field_domain(): void
    {
        $user = User::factory()->create();
        $course = $this->courseFor($user);

        $this->asConvoLabBrowser($user, convoLabUserId: $course->convolab_user_id)
            ->patchJson('/api/convolab/courses/'.$course->id, [
                'convolabUserId' => (string) Str::uuid(),
                'title' => '',
                'description' => ['not a string'],
                'maxLessonDurationMinutes' => 121,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description', 'maxLessonDurationMinutes'])
            ->assertJsonMissingValidationErrors(['convolabUserId']);

        $this->assertSame('Course', $course->fresh()->title);
    }

    public function test_browser_session_deletes_owned_course_and_hides_retries_and_other_owners(): void
    {
        $user = User::factory()->create();
        $owned = $this->courseFor($user);
        $other = $this->courseFor($user);

        $this->asConvoLabBrowser($user, convoLabUserId: $owned->convolab_user_id)
            ->deleteJson('/api/convolab/courses/'.$owned->id)
            ->assertOk()
            ->assertExactJson(['message' => 'Course deleted successfully']);
        $this->assertDatabaseMissing('content_courses', ['id' => $owned->id]);
        $this->assertDatabaseHas('content_course_tombstones', [
            'course_id' => $owned->id,
            'user_id' => $user->id,
            'convolab_user_id' => $owned->convolab_user_id,
        ]);

        $this->asConvoLabBrowser($user, convoLabUserId: $owned->convolab_user_id)
            ->deleteJson('/api/convolab/courses/'.$owned->id)
            ->assertNotFound();
        $this->asConvoLabBrowser($user, convoLabUserId: $owned->convolab_user_id)
            ->deleteJson('/api/convolab/courses/'.$other->id)
            ->assertNotFound();
        $this->assertDatabaseHas('content_courses', ['id' => $other->id]);
    }

    public function test_course_delete_ignores_client_supplied_provenance(): void
    {
        $user = User::factory()->create();
        $course = $this->courseFor($user);

        $this->asConvoLabBrowser($user, convoLabUserId: $course->convolab_user_id)
            ->deleteJson('/api/convolab/courses/'.$course->id, [
                'convolabUserId' => (string) Str::uuid(),
            ])
            ->assertOk();

        $this->assertDatabaseMissing('content_courses', ['id' => $course->id]);
        $this->assertDatabaseHas('content_course_tombstones', [
            'course_id' => $course->id,
            'convolab_user_id' => $course->convolab_user_id,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function courseFor(User $user, array $overrides = []): ContentCourse
    {
        return ContentCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => (string) Str::uuid(),
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Course',
            'description' => 'Description.',
            'status' => 'draft',
            'is_sample_content' => false,
            'is_test_course' => false,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 30,
            'l1_voice_id' => 'en-US-Neural2-J',
            'speaker1_gender' => 'male',
            'speaker2_gender' => 'female',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subHour(),
            ...$overrides,
        ]);
    }
}
