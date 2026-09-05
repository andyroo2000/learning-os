<?php

namespace Tests\Feature\Media;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListMediaAssetsCourseFilterNormalizationApiTest extends TestCase
{
    use RefreshDatabase;

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
}
