<?php

namespace Tests\Feature\Media;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsCursorPagination;
use Tests\TestCase;

class ListMediaAssetsApiTest extends TestCase
{
    use AssertsCursorPagination;
    use RefreshDatabase;

    public function test_it_lists_media_assets_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        $firstMediaAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/first.jpg')
            ->create([
                'disk' => 'media',
                'path' => 'uploads/first.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 123_456,
                'checksum_sha256' => str_repeat('a', 64),
                'original_filename' => 'first.jpg',
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ]);
        $secondMediaAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/second.jpg')
            ->create([
                'disk' => 'media',
                'path' => 'uploads/second.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 234_567,
                'checksum_sha256' => str_repeat('b', 64),
                'original_filename' => 'second.jpg',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        MediaAsset::factory()
            ->for($otherUser)
            ->create([
                'path' => 'uploads/hidden.jpg',
            ]);

        $response = $this->getJson('/api/media-assets');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $secondMediaAsset->id)
            ->assertJsonPath('data.1.id', $firstMediaAsset->id)
            ->assertJsonPath('data.0.url', 'https://cdn.example.test/uploads/second.jpg')
            ->assertJsonPath('data.0.mime_type', 'image/jpeg')
            ->assertJsonPath('data.0.size_bytes', 234_567)
            ->assertJsonPath('data.0.checksum_sha256', str_repeat('b', 64))
            ->assertJsonPath('data.0.original_filename', 'second.jpg')
            ->assertJsonPath('data.0.created_at', $secondMediaAsset->created_at?->toJSON())
            ->assertJsonPath('data.0.updated_at', $secondMediaAsset->updated_at?->toJSON())
            ->assertJsonMissingPath('data.0.disk')
            ->assertJsonMissingPath('data.0.path')
            ->assertJsonMissingPath('data.0.url_expires_at')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'url',
                        'mime_type',
                        'size_bytes',
                        'checksum_sha256',
                        'original_filename',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ]);
    }

    public function test_it_returns_an_empty_list_when_the_user_has_no_media_assets(): void
    {
        $this->signIn();
        MediaAsset::factory()->for(User::factory()->create())->create();

        $response = $this->getJson('/api/media-assets');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJson([
                'data' => [],
            ]);
    }

    public function test_it_filters_media_assets_by_course_id(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $courseDeck = Deck::factory()->for($course)->for($user)->create();
        $otherCourseDeck = Deck::factory()->for($otherCourse)->for($user)->create();
        $courseCard = Card::factory()->for($courseDeck)->create();
        $secondCourseCard = Card::factory()->for($courseDeck)->create();
        $otherCourseCard = Card::factory()->for($otherCourseDeck)->create();
        $courseMediaAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/course.jpg')
            ->create([
                'created_at' => now(),
            ]);
        $otherCourseMediaAsset = MediaAsset::factory()->for($user)->create();
        $unattachedMediaAsset = MediaAsset::factory()->for($user)->create();
        $crossUserMediaAsset = MediaAsset::factory()->for(User::factory()->create())->create();

        $courseCard->mediaAssets()->attach($courseMediaAsset->id);
        $secondCourseCard->mediaAssets()->attach($courseMediaAsset->id);
        $otherCourseCard->mediaAssets()->attach($otherCourseMediaAsset->id);
        $courseCard->mediaAssets()->attach($crossUserMediaAsset->id);

        $response = $this->getJson("/api/media-assets?course_id={$course->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $courseMediaAsset->id)
            ->assertJsonPath('data.0.url', 'https://cdn.example.test/uploads/course.jpg')
            ->assertJsonMissing([
                'id' => $otherCourseMediaAsset->id,
            ])
            ->assertJsonMissing([
                'id' => $unattachedMediaAsset->id,
            ])
            ->assertJsonMissing([
                'id' => $crossUserMediaAsset->id,
            ]);
    }

    public function test_it_trims_course_id_filters_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $courseDeck = Deck::factory()->for($course)->for($user)->create();
        $courseCard = Card::factory()->for($courseDeck)->create();
        $courseMediaAsset = MediaAsset::factory()->for($user)->create();
        $unattachedMediaAsset = MediaAsset::factory()->for($user)->create();

        $courseCard->mediaAssets()->attach($courseMediaAsset->id);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/media-assets?course_id=%20'.$course->id.'%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $courseMediaAsset->id)
            ->assertJsonMissing([
                'id' => $unattachedMediaAsset->id,
            ]);
    }

    public function test_it_lowercases_course_id_filters_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $courseDeck = Deck::factory()->for($course)->for($user)->create();
        $courseCard = Card::factory()->for($courseDeck)->create();
        $courseMediaAsset = MediaAsset::factory()->for($user)->create();
        $unattachedMediaAsset = MediaAsset::factory()->for($user)->create();

        $courseCard->mediaAssets()->attach($courseMediaAsset->id);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/media-assets?course_id='.strtoupper($course->id));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $courseMediaAsset->id)
            ->assertJsonMissing([
                'id' => $unattachedMediaAsset->id,
            ]);
    }

    public function test_it_rejects_a_blank_course_id_filter_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/media-assets?course_id=%20%20%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_it_rejects_a_malformed_course_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/media-assets?course_id=not-a-ulid');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_it_accepts_a_custom_page_size(): void
    {
        $user = $this->signIn();

        MediaAsset::factory()->count(3)->for($user)->create();

        $this->assertCursorEndpointAcceptsCustomPageSize('/api/media-assets');
    }

    public function test_it_uses_the_default_page_size_when_omitted(): void
    {
        $user = $this->signIn();

        MediaAsset::factory()->count(CursorPagination::DEFAULT_PAGE_SIZE + 1)->for($user)->create();

        $this->assertCursorEndpointUsesDefaultPageSize('/api/media-assets');
    }

    public function test_it_accepts_the_minimum_page_size(): void
    {
        $user = $this->signIn();

        MediaAsset::factory()->count(3)->for($user)->create();

        $this->assertCursorEndpointAcceptsMinimumPageSize('/api/media-assets');
    }

    public function test_it_accepts_the_maximum_page_size(): void
    {
        $user = $this->signIn();

        MediaAsset::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $this->assertCursorEndpointAcceptsMaximumPageSize('/api/media-assets');
    }

    public function test_it_rejects_page_size_above_the_maximum(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/media-assets', CursorPagination::MAX_PAGE_SIZE + 1);
    }

    public function test_it_rejects_a_page_size_below_the_minimum(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/media-assets', 0);
    }

    public function test_it_rejects_a_negative_page_size(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/media-assets', -1);
    }

    public function test_it_rejects_invalid_page_size(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/media-assets', 'abc');
    }

    public function test_it_rejects_an_array_page_size(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsArrayPageSize('/api/media-assets');
    }

    public function test_it_uses_cursor_pagination_with_a_stable_id_tiebreaker(): void
    {
        $user = $this->signIn();
        $sharedTimestamp = now()->subDays(2);

        foreach (range(1, CursorPagination::MAX_PAGE_SIZE - 1) as $index) {
            MediaAsset::factory()->for($user)->create([
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $lowTieMediaAsset = MediaAsset::factory()->for($user)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pj',
            'created_at' => $sharedTimestamp,
            'updated_at' => $sharedTimestamp,
        ]);
        $highTieMediaAsset = MediaAsset::factory()->for($user)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pk',
            'created_at' => $sharedTimestamp,
            'updated_at' => $sharedTimestamp,
        ]);

        $firstPage = $this->getJson('/api/media-assets');

        $firstPage
            ->assertOk()
            ->assertJsonCount(CursorPagination::MAX_PAGE_SIZE, 'data')
            ->assertJsonPath('data.'.(CursorPagination::MAX_PAGE_SIZE - 1).'.id', $highTieMediaAsset->id)
            ->assertJsonPath('meta.per_page', CursorPagination::MAX_PAGE_SIZE);

        $nextCursor = $firstPage->json('meta.next_cursor');

        $this->assertNotNull($nextCursor);

        $secondPage = $this->getJson("/api/media-assets?cursor={$nextCursor}");

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lowTieMediaAsset->id)
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson('/api/media-assets');

        $response->assertUnauthorized();
    }
}
