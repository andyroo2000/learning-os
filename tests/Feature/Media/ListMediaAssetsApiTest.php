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
            ->assertJsonPath('data.0.content_url', "/api/media-assets/{$secondMediaAsset->id}/content")
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
                        'content_url',
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

    public function test_it_filters_media_assets_by_deck_id(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = Deck::factory()->for($course)->for($user)->create();
        $otherDeck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create();
        $secondCard = Card::factory()->for($deck)->create();
        $otherDeckCard = Card::factory()->for($otherDeck)->create();
        $deckMediaAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/deck.jpg')
            ->create([
                'created_at' => now(),
            ]);
        $otherDeckMediaAsset = MediaAsset::factory()->for($user)->create();
        $unattachedMediaAsset = MediaAsset::factory()->for($user)->create();
        $crossUserMediaAsset = MediaAsset::factory()->for(User::factory()->create())->create();

        $card->mediaAssets()->attach($deckMediaAsset->id);
        $secondCard->mediaAssets()->attach($deckMediaAsset->id);
        $otherDeckCard->mediaAssets()->attach($otherDeckMediaAsset->id);
        $card->mediaAssets()->attach($crossUserMediaAsset->id);

        $response = $this->getJson("/api/media-assets?deck_id={$deck->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deckMediaAsset->id)
            ->assertJsonPath('data.0.url', 'https://cdn.example.test/uploads/deck.jpg')
            ->assertJsonMissing([
                'id' => $otherDeckMediaAsset->id,
            ])
            ->assertJsonMissing([
                'id' => $unattachedMediaAsset->id,
            ])
            ->assertJsonMissing([
                'id' => $crossUserMediaAsset->id,
            ]);
    }

    public function test_it_returns_empty_when_deck_id_and_course_id_are_in_different_courses(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $otherCourseDeck = Deck::factory()->for($otherCourse)->for($user)->create();
        $otherCourseCard = Card::factory()->for($otherCourseDeck)->create();
        $otherCourseMediaAsset = MediaAsset::factory()->for($user)->create();

        $otherCourseCard->mediaAssets()->attach($otherCourseMediaAsset->id);

        $response = $this->getJson("/api/media-assets?course_id={$course->id}&deck_id={$otherCourseDeck->id}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing([
                'id' => $otherCourseMediaAsset->id,
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

    public function test_it_normalizes_deck_id_filters_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();
        $otherDeckCard = Card::factory()->for($otherDeck)->create();
        $deckMediaAsset = MediaAsset::factory()->for($user)->create();
        $otherDeckMediaAsset = MediaAsset::factory()->for($user)->create();

        $card->mediaAssets()->attach($deckMediaAsset->id);
        $otherDeckCard->mediaAssets()->attach($otherDeckMediaAsset->id);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/media-assets?deck_id=%20'.strtoupper($deck->id).'%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deckMediaAsset->id)
            ->assertJsonMissing([
                'id' => $otherDeckMediaAsset->id,
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

    public function test_it_rejects_a_blank_deck_id_filter_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/media-assets?deck_id=%20%20%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_it_rejects_a_malformed_course_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/media-assets?course_id=not-a-ulid');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_it_rejects_a_malformed_deck_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/media-assets?deck_id=not-a-ulid');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_it_rejects_an_array_course_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/media-assets?course_id[]=01jzk7k5g9e1k8z6w3b4n9y2pc');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_it_rejects_an_array_deck_id_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/media-assets?deck_id[]=01jzk7k5g9e1k8z6w3b4n9y2pc');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_it_returns_empty_results_for_a_deck_id_owned_by_another_user(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $otherDeck = $this->deckFor($otherUser);
        $otherDeckCard = Card::factory()->for($otherDeck)->create();
        $otherUserMediaAsset = MediaAsset::factory()->for($otherUser)->create();

        $otherDeckCard->mediaAssets()->attach($otherUserMediaAsset->id);

        $response = $this->getJson("/api/media-assets?deck_id={$otherDeck->id}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing([
                'id' => $otherUserMediaAsset->id,
            ]);
    }

    public function test_it_excludes_deleted_card_and_deck_attachments_when_filtering_by_deck_id(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $visibleCard = Card::factory()->for($deck)->create();
        $deletedCard = Card::factory()->for($deck)->create();
        $deletedDeck = $this->deckFor($user);
        $deletedDeckCard = Card::factory()->for($deletedDeck)->create();
        $visibleMediaAsset = MediaAsset::factory()->for($user)->create([
            'created_at' => now(),
        ]);
        $deletedCardMediaAsset = MediaAsset::factory()->for($user)->create();
        $deletedDeckMediaAsset = MediaAsset::factory()->for($user)->create();

        $visibleCard->mediaAssets()->attach($visibleMediaAsset->id);
        $deletedCard->mediaAssets()->attach($deletedCardMediaAsset->id);
        $deletedDeckCard->mediaAssets()->attach($deletedDeckMediaAsset->id);
        $deletedCard->delete();
        $deletedDeck->delete();

        $activeDeckResponse = $this->getJson("/api/media-assets?deck_id={$deck->id}");

        $activeDeckResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleMediaAsset->id)
            ->assertJsonMissing([
                'id' => $deletedCardMediaAsset->id,
            ]);

        $deletedDeckResponse = $this->getJson("/api/media-assets?deck_id={$deletedDeck->id}");

        $deletedDeckResponse
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing([
                'id' => $deletedDeckMediaAsset->id,
            ]);
    }

    public function test_it_preserves_course_id_filter_when_following_a_cursor(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $courseDeck = Deck::factory()->for($course)->for($user)->create();
        $otherCourseDeck = Deck::factory()->for($otherCourse)->for($user)->create();
        $courseCard = Card::factory()->for($courseDeck)->create();
        $otherCourseCard = Card::factory()->for($otherCourseDeck)->create();
        $olderMediaAsset = MediaAsset::factory()->for($user)->create([
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);
        $newerMediaAsset = MediaAsset::factory()->for($user)->create([
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        $otherCourseMediaAsset = MediaAsset::factory()->for($user)->create([
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $courseCard->mediaAssets()->attach($olderMediaAsset->id);
        $courseCard->mediaAssets()->attach($newerMediaAsset->id);
        $otherCourseCard->mediaAssets()->attach($otherCourseMediaAsset->id);

        $firstPage = $this->getJson("/api/media-assets?course_id={$course->id}&per_page=1");

        $firstPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newerMediaAsset->id);

        $nextUrl = $firstPage->json('links.next');

        $this->assertIsString($nextUrl);
        $this->assertUrlQueryParameter($nextUrl, 'course_id', $course->id);

        $secondPage = $this->getJson($this->pathAndQueryFromUrl($nextUrl));

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $olderMediaAsset->id)
            ->assertJsonPath('links.next', null)
            ->assertJsonMissing([
                'id' => $newerMediaAsset->id,
            ])
            ->assertJsonMissing([
                'id' => $otherCourseMediaAsset->id,
            ]);
    }

    public function test_it_preserves_deck_id_filter_when_following_a_cursor(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();
        $otherDeckCard = Card::factory()->for($otherDeck)->create();
        $olderMediaAsset = MediaAsset::factory()->for($user)->create([
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);
        $newerMediaAsset = MediaAsset::factory()->for($user)->create([
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        $otherDeckMediaAsset = MediaAsset::factory()->for($user)->create([
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $card->mediaAssets()->attach($olderMediaAsset->id);
        $card->mediaAssets()->attach($newerMediaAsset->id);
        $otherDeckCard->mediaAssets()->attach($otherDeckMediaAsset->id);

        $firstPage = $this->getJson("/api/media-assets?deck_id={$deck->id}&per_page=1");

        $firstPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newerMediaAsset->id);

        $nextUrl = $firstPage->json('links.next');

        $this->assertIsString($nextUrl);
        $this->assertUrlQueryParameter($nextUrl, 'deck_id', $deck->id);

        $secondPage = $this->getJson($this->pathAndQueryFromUrl($nextUrl));

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $olderMediaAsset->id)
            ->assertJsonPath('links.next', null)
            ->assertJsonMissing([
                'id' => $newerMediaAsset->id,
            ])
            ->assertJsonMissing([
                'id' => $otherDeckMediaAsset->id,
            ]);
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

    private function pathAndQueryFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        $this->assertIsString($path);
        $this->assertIsString($query);

        return "{$path}?{$query}";
    }
}
