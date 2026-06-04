<?php

namespace Tests\Feature\Media;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Actions\ListMediaAssetsAction;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListMediaAssetsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_media_assets_for_the_user(): void
    {
        $user = User::factory()->create();
        $ownedMediaAsset = MediaAsset::factory()->for($user)->create();
        MediaAsset::factory()->for(User::factory()->create())->create();

        $mediaAssets = app(ListMediaAssetsAction::class)->handle($user->id);

        $this->assertSame([$ownedMediaAsset->id], collect($mediaAssets->items())->pluck('id')->all());
    }

    public function test_it_filters_media_assets_by_attached_card_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $courseDeck = Deck::factory()->for($course)->for($user)->create();
        $otherCourseDeck = Deck::factory()->for($otherCourse)->for($user)->create();
        $courseCard = Card::factory()->for($courseDeck)->create();
        $secondCourseCard = Card::factory()->for($courseDeck)->create();
        $otherCourseCard = Card::factory()->for($otherCourseDeck)->create();
        $courseMediaAsset = MediaAsset::factory()->for($user)->create([
            'created_at' => now(),
        ]);
        $otherCourseMediaAsset = MediaAsset::factory()->for($user)->create();
        $unattachedMediaAsset = MediaAsset::factory()->for($user)->create();
        $crossUserMediaAsset = MediaAsset::factory()->for(User::factory()->create())->create();

        $courseCard->mediaAssets()->attach($courseMediaAsset->id);
        $secondCourseCard->mediaAssets()->attach($courseMediaAsset->id);
        $otherCourseCard->mediaAssets()->attach($otherCourseMediaAsset->id);
        $courseCard->mediaAssets()->attach($crossUserMediaAsset->id);

        $mediaAssets = app(ListMediaAssetsAction::class)->handle(
            userId: $user->id,
            courseId: $course->id,
        );

        $this->assertSame([$courseMediaAsset->id], collect($mediaAssets->items())->pluck('id')->all());
        $this->assertNotContains($otherCourseMediaAsset->id, collect($mediaAssets->items())->pluck('id')->all());
        $this->assertNotContains($unattachedMediaAsset->id, collect($mediaAssets->items())->pluck('id')->all());
        $this->assertNotContains($crossUserMediaAsset->id, collect($mediaAssets->items())->pluck('id')->all());
    }

    public function test_it_rejects_blank_course_id_filters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Media asset course_id filter must not be blank when provided.');

        app(ListMediaAssetsAction::class)->handle(
            userId: User::factory()->create()->id,
            courseId: '   ',
        );
    }

    public function test_it_allows_a_smaller_page_size(): void
    {
        $user = User::factory()->create();

        MediaAsset::factory()->count(3)->for($user)->create();

        $mediaAssets = app(ListMediaAssetsAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(2),
        );

        $this->assertSame(2, $mediaAssets->perPage());
        $this->assertCount(2, $mediaAssets->items());
    }

    public function test_it_uses_the_max_page_size_by_default(): void
    {
        $user = User::factory()->create();

        MediaAsset::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $mediaAssets = app(ListMediaAssetsAction::class)->handle($user->id);

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $mediaAssets->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $mediaAssets->items());
    }

    public function test_it_caps_page_size(): void
    {
        $user = User::factory()->create();

        MediaAsset::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $mediaAssets = app(ListMediaAssetsAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(200),
        );

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $mediaAssets->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $mediaAssets->items());
    }
}
