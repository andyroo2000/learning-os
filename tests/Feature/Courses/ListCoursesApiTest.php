<?php

namespace Tests\Feature\Courses;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Models\Course;
use App\Models\User;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsCursorPagination;
use Tests\TestCase;

class ListCoursesApiTest extends TestCase
{
    use AssertsCursorPagination;
    use RefreshDatabase;

    public function test_it_lists_courses_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        $firstCourse = Course::factory()->for($user)->create([
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'status' => CourseStatus::Draft,
            'native_language' => 'en',
            'target_language' => 'ja',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $secondCourse = Course::factory()->ready()->for($user)->create([
            'title' => 'Italian Food Conversations',
            'description' => null,
            'native_language' => 'en',
            'target_language' => 'it',
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);
        $otherCourse = Course::factory()->for($otherUser)->create([
            'title' => 'Hidden Spanish Course',
        ]);

        $response = $this->getJson('/api/courses');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $secondCourse->id)
            ->assertJsonPath('data.1.id', $firstCourse->id)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'description',
                        'status',
                        'native_language',
                        'target_language',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ])
            ->assertJsonFragment([
                'id' => $firstCourse->id,
                'title' => 'Japanese Travel Foundations',
                'description' => 'Audio-first course for common travel scenarios.',
                'status' => 'draft',
                'native_language' => 'en',
                'target_language' => 'ja',
                'created_at' => $firstCourse->created_at?->toJSON(),
                'updated_at' => $firstCourse->updated_at?->toJSON(),
            ])
            ->assertJsonFragment([
                'id' => $secondCourse->id,
                'title' => 'Italian Food Conversations',
                'description' => null,
                'status' => 'ready',
                'native_language' => 'en',
                'target_language' => 'it',
                'created_at' => $secondCourse->created_at?->toJSON(),
                'updated_at' => $secondCourse->updated_at?->toJSON(),
            ])
            ->assertJsonMissing([
                'id' => $otherCourse->id,
            ]);
    }

    public function test_it_returns_an_empty_list_when_the_user_has_no_courses(): void
    {
        $this->signIn();
        Course::factory()->for(User::factory()->create())->create();

        $response = $this->getJson('/api/courses');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJson([
                'data' => [],
            ]);
    }

    public function test_it_excludes_soft_deleted_courses(): void
    {
        $user = $this->signIn();
        $visibleCourse = Course::factory()->for($user)->create();
        $deletedCourse = Course::factory()->for($user)->create();

        $deletedCourse->delete();

        $response = $this->getJson('/api/courses');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleCourse->id)
            ->assertJsonMissing([
                'id' => $deletedCourse->id,
            ]);
    }

    public function test_it_filters_courses_by_status(): void
    {
        $user = $this->signIn();
        $draftCourse = Course::factory()->draft()->for($user)->create([
            'updated_at' => now()->subMinute(),
        ]);
        $readyCourse = Course::factory()->ready()->for($user)->create([
            'updated_at' => now(),
        ]);

        Course::factory()->generating()->for($user)->create();
        Course::factory()->ready()->for(User::factory()->create())->create();

        $response = $this->getJson('/api/courses?status=ready');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $readyCourse->id)
            ->assertJsonPath('data.0.status', CourseStatus::Ready->value)
            ->assertJsonMissing([
                'id' => $draftCourse->id,
            ]);
    }

    public function test_status_filters_preserve_query_strings_across_cursor_pages(): void
    {
        $user = $this->signIn();

        Course::factory()->draft()->for($user)->create();
        Course::factory()->ready()->for($user)->create([
            'title' => 'Older Ready Course',
            'updated_at' => now()->subMinute(),
        ]);
        $newerReadyCourse = Course::factory()->ready()->for($user)->create([
            'title' => 'Newer Ready Course',
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/courses?status=ready&per_page=1');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newerReadyCourse->id)
            ->assertJsonPath('links.next', fn (string $url): bool => str_contains($url, 'status=ready'));
    }

    public function test_it_trims_status_filters(): void
    {
        $user = $this->signIn();
        $readyCourse = Course::factory()->ready()->for($user)->create();

        Course::factory()->draft()->for($user)->create();

        $response = $this->getJson('/api/courses?status=%20ready%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $readyCourse->id);
    }

    public function test_it_uses_cursor_pagination_with_a_stable_id_tiebreaker(): void
    {
        $user = $this->signIn();
        $sharedTimestamp = now()->subDays(2);

        foreach (range(1, CursorPagination::MAX_PAGE_SIZE - 1) as $index) {
            Course::factory()->for($user)->create([
                'title' => "Newer Course {$index}",
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $lowTieCourse = Course::factory()->for($user)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pe',
            'title' => 'Boundary Low',
            'created_at' => $sharedTimestamp,
            'updated_at' => $sharedTimestamp,
        ]);
        $highTieCourse = Course::factory()->for($user)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pf',
            'title' => 'Boundary High',
            'created_at' => $sharedTimestamp,
            'updated_at' => $sharedTimestamp,
        ]);

        $firstPage = $this->getJson('/api/courses');

        $firstPage
            ->assertOk()
            ->assertJsonCount(CursorPagination::MAX_PAGE_SIZE, 'data')
            ->assertJsonPath('data.0.title', 'Newer Course 1')
            ->assertJsonPath('data.'.(CursorPagination::MAX_PAGE_SIZE - 1).'.id', $highTieCourse->id)
            ->assertJsonPath('meta.per_page', CursorPagination::MAX_PAGE_SIZE);

        $nextCursor = $firstPage->json('meta.next_cursor');

        $this->assertNotNull($nextCursor);

        $secondPage = $this->getJson("/api/courses?cursor={$nextCursor}");

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lowTieCourse->id)
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_it_accepts_a_custom_page_size(): void
    {
        $user = $this->signIn();

        Course::factory()->count(3)->for($user)->create();

        $this->assertCursorEndpointAcceptsCustomPageSize('/api/courses');
    }

    public function test_it_uses_the_default_page_size_when_omitted(): void
    {
        $user = $this->signIn();

        Course::factory()->count(CursorPagination::DEFAULT_PAGE_SIZE + 1)->for($user)->create();

        $this->assertCursorEndpointUsesDefaultPageSize('/api/courses');
    }

    public function test_it_accepts_the_minimum_page_size(): void
    {
        $user = $this->signIn();

        Course::factory()->count(3)->for($user)->create();

        $this->assertCursorEndpointAcceptsMinimumPageSize('/api/courses');
    }

    public function test_it_accepts_the_maximum_page_size(): void
    {
        $user = $this->signIn();

        Course::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $this->assertCursorEndpointAcceptsMaximumPageSize('/api/courses');
    }

    public function test_it_rejects_a_page_size_above_the_maximum(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/courses', CursorPagination::MAX_PAGE_SIZE + 1);
    }

    public function test_it_rejects_a_page_size_below_the_minimum(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/courses', 0);
    }

    public function test_it_rejects_a_negative_page_size(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/courses', -1);
    }

    public function test_it_rejects_a_non_numeric_page_size(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/courses', 'abc');
    }

    public function test_it_rejects_invalid_status_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/courses?status=published');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_it_rejects_blank_status_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/courses?status=%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_it_requires_authentication(): void
    {
        Course::factory()->create();

        $response = $this->getJson('/api/courses');

        $response->assertUnauthorized();
    }
}
