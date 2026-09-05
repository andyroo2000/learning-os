<?php

namespace Tests\Feature\Media;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsCursorPagination;
use Tests\TestCase;

class ListMediaAssetsFilteredCursorApiTest extends TestCase
{
    use AssertsCursorPagination;
    use RefreshDatabase;

    public function test_it_preserves_course_id_filter_when_following_a_cursor(): void
    {
        $user = $this->signIn();
        [$course, $courseCard] = $this->courseCardFor($user);
        [, $otherCourseCard] = $this->courseCardFor($user);
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

        $nextPath = $this->pathAndQueryFromUrl($nextUrl);

        $this->assertStringStartsWith('/api/media-assets?', $nextPath);

        $secondPage = $this->getJson($nextPath);

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

        $nextPath = $this->pathAndQueryFromUrl($nextUrl);

        $this->assertStringStartsWith('/api/media-assets?', $nextPath);

        $secondPage = $this->getJson($nextPath);

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

    /**
     * @return array{Course, Card}
     */
    private function courseCardFor(User $user): array
    {
        $course = Course::factory()->for($user)->create();
        $deck = Deck::factory()->for($course)->for($user)->create();

        return [$course, Card::factory()->for($deck)->create()];
    }
}
