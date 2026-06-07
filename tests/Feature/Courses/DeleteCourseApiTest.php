<?php

namespace Tests\Feature\Courses;

use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Support\CourseRateLimiter;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Courses\Concerns\UsesCourseRateLimitOverrides;
use Tests\TestCase;

class DeleteCourseApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesCourseRateLimitOverrides;

    public function test_it_deletes_an_owned_course(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create([
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
        ]);

        $response = $this->deleteJson("/api/courses/{$course->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('courses', [
            'id' => $course->id,
        ]);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('courses', $entry->domain);
        $this->assertSame('course', $entry->resource_type);
        $this->assertSame($course->id, $entry->resource_id);
        $this->assertSame('delete', $entry->operation->value);
        $this->assertNotNull($entry->payload['deleted_at']);
    }

    public function test_it_deletes_course_scoped_decks_and_cards(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $card = Card::factory()->for($deck)->create();
        $standaloneDeck = Deck::factory()->create(['user_id' => $user->id]);
        $standaloneCard = Card::factory()->for($standaloneDeck)->create();

        $response = $this->deleteJson("/api/courses/{$course->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('courses', ['id' => $course->id]);
        $this->assertSoftDeleted('decks', ['id' => $deck->id]);
        $this->assertSoftDeleted('cards', ['id' => $card->id]);
        $this->assertDatabaseHas('decks', [
            'id' => $standaloneDeck->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $standaloneCard->id,
            'deleted_at' => null,
        ]);

        $entries = SyncFeedEntry::query()
            ->orderBy('checkpoint')
            ->get();

        $this->assertSame(['card', 'deck', 'course'], $entries->pluck('resource_type')->all());
        $this->assertSame($course->id, $entries->last()->resource_id);
    }

    public function test_it_is_idempotent_for_an_already_soft_deleted_course(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        try {
            $course->delete();
            $originalDeletedAt = $course->refresh()->deleted_at;

            Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:01'));

            $response = $this->deleteJson("/api/courses/{$course->id}");

            $response->assertNoContent();

            $this->assertDatabaseHas('courses', [
                'id' => $course->id,
                'deleted_at' => $originalDeletedAt?->toDateTimeString(),
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_delete_is_rate_limited_by_user(): void
    {
        $user = $this->signIn();
        $courses = Course::factory()->count(3)->for($user)->create();
        $otherUser = User::factory()->create();
        $otherCourse = Course::factory()->for($otherUser)->create();

        $this->withCourseRateLimitOverride(CourseRateLimiter::DELETE_NAME, [$user->id, $otherUser->id], function () use ($user, $courses, $otherUser, $otherCourse): void {
            foreach ($courses->take(2) as $course) {
                $this
                    ->deleteJson("/api/courses/{$course->id}")
                    ->assertNoContent();
            }

            $this->signIn($otherUser);

            $this
                ->deleteJson("/api/courses/{$otherCourse->id}")
                ->assertNoContent();

            $this->signIn($user);

            $blockedCourse = $courses->last();

            $this
                ->deleteJson("/api/courses/{$blockedCourse->id}")
                ->assertTooManyRequests();

            $this
                ->getJson("/api/courses/{$blockedCourse->id}")
                ->assertOk()
                ->assertJsonPath('data.id', $blockedCourse->id);

            $this->assertSoftDeleted('courses', ['id' => $courses[0]->id]);
            $this->assertSoftDeleted('courses', ['id' => $courses[1]->id]);
            $this->assertDatabaseHas('courses', [
                'id' => $blockedCourse->id,
                'deleted_at' => null,
            ]);
        });
    }

    public function test_it_hides_another_users_course(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $course = Course::factory()->for($otherUser)->create();

        $response = $this->deleteJson("/api/courses/{$course->id}");

        $response->assertNotFound();

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'deleted_at' => null,
        ]);
    }

    public function test_it_hides_another_users_soft_deleted_course(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $course = Course::factory()->for($otherUser)->create();

        $course->delete();

        $response = $this->deleteJson("/api/courses/{$course->id}");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_missing_course(): void
    {
        $this->signIn();

        $response = $this->deleteJson('/api/courses/'.((string) Str::ulid()));

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_course_id(): void
    {
        $this->signIn();

        $response = $this->deleteJson('/api/courses/not-a-ulid');

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $course = Course::factory()->create();

        $response = $this->deleteJson("/api/courses/{$course->id}");

        $response->assertUnauthorized();

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'deleted_at' => null,
        ]);
    }

    public function test_it_requires_authentication_for_a_soft_deleted_course(): void
    {
        $course = Course::factory()->create();

        $course->delete();

        $response = $this->deleteJson("/api/courses/{$course->id}");

        $response->assertUnauthorized();
    }
}
