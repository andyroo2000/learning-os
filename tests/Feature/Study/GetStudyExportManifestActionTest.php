<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\GetStudyExportManifestAction;
use App\Domain\Sync\Models\SyncFeedEntry;
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
        $currentCheckpoint = SyncFeedEntry::factory()->for($user)->create();
        SyncFeedEntry::factory()->for($otherUser)->create();
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
        $this->assertSame($currentCheckpoint->checkpoint, $manifest['current_checkpoint']);
        $this->assertSame([
            'courses' => ['total' => 1],
            'decks' => ['total' => 1],
            'cards' => ['total' => 1],
            'review_events' => ['total' => 1],
            'media_assets' => ['total' => 1],
        ], $manifest['sections']);
    }

    public function test_it_reports_zero_current_checkpoint_when_the_user_has_no_sync_feed_entries(): void
    {
        $user = User::factory()->create();
        SyncFeedEntry::factory()->for(User::factory()->create())->create();

        $manifest = app(GetStudyExportManifestAction::class)->handle($user->id);

        $this->assertSame(0, $manifest['current_checkpoint']);
    }
}
