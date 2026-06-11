<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\GetStudyExportManifestAction;
use App\Domain\Study\Actions\ListStudyExportCardDraftsAction;
use App\Domain\Study\Actions\ListStudyExportCardMediaAction;
use App\Domain\Study\Actions\ListStudyExportCardsAction;
use App\Domain\Study\Actions\ListStudyExportCoursesAction;
use App\Domain\Study\Actions\ListStudyExportDecksAction;
use App\Domain\Study\Actions\ListStudyExportImportJobsAction;
use App\Domain\Study\Actions\ListStudyExportMediaAssetsAction;
use App\Domain\Study\Actions\ListStudyExportReviewEventsAction;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        StudyCardDraft::factory()->for($user)->create();
        StudyImportJob::factory()->for($user)->create();
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $otherMediaAsset = MediaAsset::factory()->for($otherUser)->create();
        $activeCard->mediaAssets()->attach($mediaAsset->id);
        $activeCard->mediaAssets()->attach($otherMediaAsset->id);
        $deletedCard->mediaAssets()->attach($mediaAsset->id);
        $deletedDeckCard->mediaAssets()->attach($mediaAsset->id);
        $currentCheckpoint = SyncFeedEntry::factory()->for($user)->create();
        SyncFeedEntry::factory()->for($otherUser)->create();
        StudyCardDraft::factory()->for($otherUser)->create();
        StudyImportJob::factory()->for($otherUser)->create();
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
            'settings' => ['total' => 1],
            'courses' => ['total' => 1],
            'decks' => ['total' => 1],
            'cards' => ['total' => 1],
            'card_drafts' => ['total' => 1],
            'card_media' => ['total' => 1],
            'review_events' => ['total' => 1],
            'imports' => ['total' => 1],
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

    public function test_it_reports_settings_as_an_effective_singleton_without_materializing_defaults(): void
    {
        $user = User::factory()->create();

        $manifest = app(GetStudyExportManifestAction::class)->handle($user->id);

        $this->assertSame(['total' => 1], $manifest['sections']['settings']);
        $this->assertDatabaseMissing('study_settings', [
            'user_id' => $user->id,
            'new_cards_per_day' => StudySettings::DEFAULT_NEW_CARDS_PER_DAY,
        ]);
    }

    public function test_manifest_totals_match_current_export_section_actions(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $deletedCourse = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $deletedDeck = $this->deckFor($user);
        $activeCard = Card::factory()->for($deck)->create();
        $deletedCard = Card::factory()->for($deck)->create();
        $cardInDeletedDeck = Card::factory()->for($deletedDeck)->create();
        $otherCard = $this->cardFor($otherUser);
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $deletedMediaAsset = MediaAsset::factory()->for($user)->create();
        $otherUserMediaAsset = MediaAsset::factory()->for($otherUser)->create();

        CardReviewEvent::factory()->for($activeCard)->count(2)->create();
        CardReviewEvent::factory()->for($deletedCard)->create();
        CardReviewEvent::factory()->for($cardInDeletedDeck)->create();
        CardReviewEvent::factory()->for($otherCard)->create();
        StudyCardDraft::factory()->for($user)->count(2)->create();
        StudyCardDraft::factory()->for($otherUser)->create();
        StudyImportJob::factory()->for($user)->count(2)->create();
        StudyImportJob::factory()->for($otherUser)->create();
        MediaAsset::factory()->for($user)->create();
        MediaAsset::factory()->for($otherUser)->create();
        $activeCard->mediaAssets()->attach($mediaAsset->id);
        // Hard-deleting the asset leaves an orphaned pivot; the inner join should exclude it.
        $activeCard->mediaAssets()->attach($deletedMediaAsset->id);
        $activeCard->mediaAssets()->attach($otherUserMediaAsset->id);
        $deletedCard->mediaAssets()->attach($mediaAsset->id);
        $cardInDeletedDeck->mediaAssets()->attach($mediaAsset->id);
        $otherCard->mediaAssets()->attach($mediaAsset->id);

        $deletedCourse->delete();
        $deletedMediaAsset->delete();
        $deletedCard->delete();
        $deletedDeck->delete();

        $manifest = app(GetStudyExportManifestAction::class)->handle($user->id);

        $this->assertSame(1, $manifest['sections']['settings']['total']);
        $this->assertSame(
            app(ListStudyExportCoursesAction::class)->handle($user->id)->count(),
            $manifest['sections']['courses']['total'],
        );
        $this->assertSame(
            app(ListStudyExportDecksAction::class)->handle($user->id)->count(),
            $manifest['sections']['decks']['total'],
        );
        $this->assertSame(
            app(ListStudyExportCardsAction::class)->handle($user->id)->count(),
            $manifest['sections']['cards']['total'],
        );
        $this->assertSame(
            app(ListStudyExportCardDraftsAction::class)->handle($user->id)->count(),
            $manifest['sections']['card_drafts']['total'],
        );
        $this->assertSame(
            app(ListStudyExportCardMediaAction::class)->handle($user->id)->count(),
            $manifest['sections']['card_media']['total'],
        );
        $this->assertSame(
            app(ListStudyExportReviewEventsAction::class)->handle($user->id)->count(),
            $manifest['sections']['review_events']['total'],
        );
        $this->assertSame(
            app(ListStudyExportImportJobsAction::class)->handle($user->id)->count(),
            $manifest['sections']['imports']['total'],
        );
        $this->assertSame(
            app(ListStudyExportMediaAssetsAction::class)->handle($user->id)->count(),
            $manifest['sections']['media_assets']['total'],
        );
    }

    public function test_it_loads_export_counts_with_one_manifest_query(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $card = Card::factory()->for($deck)->create();
        CardReviewEvent::factory()->for($card)->create();
        StudyCardDraft::factory()->for($user)->create();
        StudyImportJob::factory()->for($user)->create();
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $deletedMediaAsset = MediaAsset::factory()->for($user)->create();
        $card->mediaAssets()->attach($mediaAsset->id);
        $card->mediaAssets()->attach($deletedMediaAsset->id);
        $deletedMediaAsset->delete();
        SyncFeedEntry::factory()->for($user)->create();

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $manifest = app(GetStudyExportManifestAction::class)->handle($user->id);
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(1, $manifest['sections']['courses']['total']);
        $this->assertSame(1, $manifest['sections']['decks']['total']);
        $this->assertSame(1, $manifest['sections']['cards']['total']);
        $this->assertSame(1, $manifest['sections']['card_drafts']['total']);
        $this->assertSame(1, $manifest['sections']['card_media']['total']);
        $this->assertSame(1, $manifest['sections']['review_events']['total']);
        $this->assertSame(1, $manifest['sections']['imports']['total']);
        $this->assertSame(1, $manifest['sections']['media_assets']['total']);

        $this->assertCount(1, $queries, $queries->pluck('query')->implode("\n"));
        $this->assertStringContainsString('SELECT COUNT(courses.id)', $queries->first()['query']);
        $this->assertStringContainsString('SELECT COUNT(*)', $queries->first()['query']);
    }
}
