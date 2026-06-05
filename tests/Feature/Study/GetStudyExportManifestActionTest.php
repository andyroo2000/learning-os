<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\GetStudyExportManifestAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GetStudyExportManifestActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_current_export_section_counts_for_the_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $deletedCourse = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $deletedDeck = $this->deckFor($user);
        $activeCard = Card::factory()->for($deck)->create();
        $deletedCard = Card::factory()->for($deck)->create();
        $deletedDeckCard = Card::factory()->for($deletedDeck)->create();

        CardReviewEvent::factory()->for($activeCard)->create();
        CardReviewEvent::factory()->for($deletedCard)->create();
        CardReviewEvent::factory()->for($deletedDeckCard)->create();
        MediaAsset::factory()->for($user)->create();
        MediaAsset::factory()->for($otherUser)->create();
        Course::factory()->for($otherUser)->create();
        Card::factory()->for($this->deckFor($otherUser))->create();

        $deletedCourse->delete();
        $deletedCard->delete();
        $deletedDeck->delete();

        $manifest = app(GetStudyExportManifestAction::class)->handle(
            userId: $user->id,
            now: Carbon::parse('2026-06-05T12:34:56Z'),
        );

        $this->assertSame('2026-06-05T12:34:56.000000Z', $manifest['exported_at']);
        $this->assertSame([
            'courses' => [
                'total' => 1,
                'path' => '/api/study/export/courses',
            ],
            'decks' => [
                'total' => 1,
                'path' => '/api/study/export/decks',
            ],
            'cards' => [
                'total' => 1,
                'path' => '/api/study/export/cards',
            ],
            'review_events' => [
                'total' => 1,
                'path' => '/api/study/export/review-events',
            ],
            'media_assets' => [
                'total' => 1,
                'path' => '/api/study/export/media-assets',
            ],
        ], $manifest['sections']);
    }
}
