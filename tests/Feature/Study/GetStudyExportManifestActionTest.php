<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
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
        $currentCheckpoint = $this->seedCurrentExportSectionRecords($user);

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

    private function seedCurrentExportSectionRecords(User $user): SyncFeedEntry
    {
        $otherUser = User::factory()->create();
        $cards = $this->currentExportCards($user);

        $this->seedCurrentReviewDraftImportRecords($user, $cards);
        $this->attachCurrentExportMedia($user, $otherUser, $cards);
        $currentCheckpoint = SyncFeedEntry::factory()->for($user)->create();
        $this->seedOtherUserExportRecords($otherUser);

        $cards['deletedCourse']->delete();
        $cards['deletedCard']->delete();
        $cards['deletedDeck']->delete();

        return $currentCheckpoint;
    }

    /** @return array{deletedCourse: Course, deletedDeck: Deck, activeCard: Card, deletedCard: Card, deletedDeckCard: Card} */
    private function currentExportCards(User $user): array
    {
        $course = Course::factory()->for($user)->create();
        $deletedCourse = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $deletedDeck = $this->deckFor($user);
        $activeCard = Card::factory()->for($deck)->create();
        $deletedCard = Card::factory()->for($deck)->create();
        $deletedDeckCard = Card::factory()->for($deletedDeck)->create();

        return compact('deletedCourse', 'deletedDeck', 'activeCard', 'deletedCard', 'deletedDeckCard');
    }

    /** @param array{deletedCourse: Course, deletedDeck: Deck, activeCard: Card, deletedCard: Card, deletedDeckCard: Card} $cards */
    private function seedCurrentReviewDraftImportRecords(User $user, array $cards): void
    {
        CardReviewEvent::factory()->for($cards['activeCard'])->create();
        CardReviewEvent::factory()->for($cards['deletedCard'])->create();
        CardReviewEvent::factory()->for($cards['deletedDeckCard'])->create();
        StudyCardDraft::factory()->for($user)->create();
        StudyImportJob::factory()->for($user)->create();
    }

    /** @param array{deletedCourse: Course, deletedDeck: Deck, activeCard: Card, deletedCard: Card, deletedDeckCard: Card} $cards */
    private function attachCurrentExportMedia(User $user, User $otherUser, array $cards): void
    {
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $otherMediaAsset = MediaAsset::factory()->for($otherUser)->create();
        $cards['activeCard']->mediaAssets()->attach($mediaAsset->id);
        $cards['activeCard']->mediaAssets()->attach($otherMediaAsset->id);
        $cards['deletedCard']->mediaAssets()->attach($mediaAsset->id);
        $cards['deletedDeckCard']->mediaAssets()->attach($mediaAsset->id);
    }

    private function seedOtherUserExportRecords(User $otherUser): void
    {
        SyncFeedEntry::factory()->for($otherUser)->create();
        StudyCardDraft::factory()->for($otherUser)->create();
        StudyImportJob::factory()->for($otherUser)->create();
        Course::factory()->for($otherUser)->create();
        Card::factory()->for($this->deckFor($otherUser))->create();
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
        $this->seedManifestComparisonRecords($user);

        $manifest = app(GetStudyExportManifestAction::class)->handle($user->id);

        $this->assertManifestSectionsMatch($user, $manifest);
    }

    private function seedManifestComparisonRecords(User $user): void
    {
        $records = $this->manifestComparisonCards($user);
        $records = array_merge($records, $this->manifestComparisonMedia($user, $records['otherUser']));
        $this->seedManifestComparisonReviews($records);
        $this->seedManifestComparisonUserRecords($user, $records['otherUser']);
        $this->attachManifestComparisonMedia($records);
        $this->deleteExcludedManifestComparisonRecords($records);
    }

    /** @return array{otherUser: User, deletedCourse: Course, deletedDeck: Deck, activeCard: Card, deletedCard: Card, cardInDeletedDeck: Card, otherCard: Card} */
    private function manifestComparisonCards(User $user): array
    {
        $otherUser = User::factory()->create();
        $course = Course::factory()->for($user)->create();
        $deletedCourse = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $deletedDeck = $this->deckFor($user);
        $activeCard = Card::factory()->for($deck)->create();
        $deletedCard = Card::factory()->for($deck)->create();
        $cardInDeletedDeck = Card::factory()->for($deletedDeck)->create();
        $otherCard = $this->cardFor($otherUser);

        return compact(
            'otherUser',
            'deletedCourse',
            'deletedDeck',
            'activeCard',
            'deletedCard',
            'cardInDeletedDeck',
            'otherCard',
        );
    }

    /** @return array{mediaAsset: MediaAsset, deletedMediaAsset: MediaAsset, otherUserMediaAsset: MediaAsset} */
    private function manifestComparisonMedia(User $user, User $otherUser): array
    {
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $deletedMediaAsset = MediaAsset::factory()->for($user)->create();
        $otherUserMediaAsset = MediaAsset::factory()->for($otherUser)->create();

        return compact('mediaAsset', 'deletedMediaAsset', 'otherUserMediaAsset');
    }

    /** @param array{otherUser: User, deletedCourse: Course, deletedDeck: Deck, activeCard: Card, deletedCard: Card, cardInDeletedDeck: Card, otherCard: Card, mediaAsset: MediaAsset, deletedMediaAsset: MediaAsset, otherUserMediaAsset: MediaAsset} $records */
    private function seedManifestComparisonReviews(array $records): void
    {
        CardReviewEvent::factory()->for($records['activeCard'])->count(2)->create();
        CardReviewEvent::factory()->for($records['deletedCard'])->create();
        CardReviewEvent::factory()->for($records['cardInDeletedDeck'])->create();
        CardReviewEvent::factory()->for($records['otherCard'])->create();
    }

    private function seedManifestComparisonUserRecords(User $user, User $otherUser): void
    {
        StudyCardDraft::factory()->for($user)->count(2)->create();
        StudyCardDraft::factory()->for($otherUser)->create();
        StudyImportJob::factory()->for($user)->count(2)->create();
        StudyImportJob::factory()->for($otherUser)->create();
        MediaAsset::factory()->for($user)->create();
        MediaAsset::factory()->for($otherUser)->create();
    }

    /** @param array{otherUser: User, deletedCourse: Course, deletedDeck: Deck, activeCard: Card, deletedCard: Card, cardInDeletedDeck: Card, otherCard: Card, mediaAsset: MediaAsset, deletedMediaAsset: MediaAsset, otherUserMediaAsset: MediaAsset} $records */
    private function attachManifestComparisonMedia(array $records): void
    {
        $records['activeCard']->mediaAssets()->attach($records['mediaAsset']->id);
        // Hard-deleting the asset leaves an orphaned pivot; the inner join should exclude it.
        $records['activeCard']->mediaAssets()->attach($records['deletedMediaAsset']->id);
        $records['activeCard']->mediaAssets()->attach($records['otherUserMediaAsset']->id);
        $records['deletedCard']->mediaAssets()->attach($records['mediaAsset']->id);
        $records['cardInDeletedDeck']->mediaAssets()->attach($records['mediaAsset']->id);
        $records['otherCard']->mediaAssets()->attach($records['mediaAsset']->id);
    }

    /** @param array{otherUser: User, deletedCourse: Course, deletedDeck: Deck, activeCard: Card, deletedCard: Card, cardInDeletedDeck: Card, otherCard: Card, mediaAsset: MediaAsset, deletedMediaAsset: MediaAsset, otherUserMediaAsset: MediaAsset} $records */
    private function deleteExcludedManifestComparisonRecords(array $records): void
    {
        $records['deletedCourse']->delete();
        $records['deletedMediaAsset']->delete();
        $records['deletedCard']->delete();
        $records['deletedDeck']->delete();
    }

    /** @param array<string, mixed> $manifest */
    private function assertManifestSectionsMatch(User $user, array $manifest): void
    {
        $this->assertSame(1, $manifest['sections']['settings']['total']);

        $sectionActions = [
            'courses' => ListStudyExportCoursesAction::class,
            'decks' => ListStudyExportDecksAction::class,
            'cards' => ListStudyExportCardsAction::class,
            'card_drafts' => ListStudyExportCardDraftsAction::class,
            'card_media' => ListStudyExportCardMediaAction::class,
            'review_events' => ListStudyExportReviewEventsAction::class,
            'imports' => ListStudyExportImportJobsAction::class,
            'media_assets' => ListStudyExportMediaAssetsAction::class,
        ];

        foreach ($sectionActions as $section => $action) {
            $this->assertSame(
                app($action)->handle($user->id)->count(),
                $manifest['sections'][$section]['total'],
            );
        }
    }

    public function test_it_loads_export_counts_with_one_manifest_query(): void
    {
        $user = User::factory()->create();
        $this->seedManifestQueryRecords($user);

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

    private function seedManifestQueryRecords(User $user): void
    {
        $card = $this->manifestQueryCard($user);
        $this->seedManifestQueryActivityRecords($user, $card);
        $this->attachManifestQueryMedia($user, $card);
        SyncFeedEntry::factory()->for($user)->create();
    }

    private function manifestQueryCard(User $user): Card
    {
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);

        return Card::factory()->for($deck)->create();
    }

    private function seedManifestQueryActivityRecords(User $user, Card $card): void
    {
        CardReviewEvent::factory()->for($card)->create();
        StudyCardDraft::factory()->for($user)->create();
        StudyImportJob::factory()->for($user)->create();
    }

    private function attachManifestQueryMedia(User $user, Card $card): void
    {
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $deletedMediaAsset = MediaAsset::factory()->for($user)->create();
        $card->mediaAssets()->attach($mediaAsset->id);
        $card->mediaAssets()->attach($deletedMediaAsset->id);
        $deletedMediaAsset->delete();
    }
}
