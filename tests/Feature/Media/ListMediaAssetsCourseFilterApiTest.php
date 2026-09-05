<?php

namespace Tests\Feature\Media;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListMediaAssetsCourseFilterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_media_assets_by_course_id(): void
    {
        $user = $this->signIn();
        [$course, $courseDeck, $courseCard] = $this->courseCardFor($user);
        [, , $otherCourseCard] = $this->courseCardFor($user);
        $secondCourseCard = Card::factory()->for($courseDeck)->create();
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

    /**
     * @return array{Course, Deck, Card}
     */
    private function courseCardFor(User $user): array
    {
        $course = Course::factory()->for($user)->create();
        $deck = Deck::factory()->for($course)->for($user)->create();
        $card = Card::factory()->for($deck)->create();

        return [$course, $deck, $card];
    }
}
