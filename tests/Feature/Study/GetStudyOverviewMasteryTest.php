<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class GetStudyOverviewMasteryTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_reports_fsrs_mastery_spread_and_advisory_learning_readiness(): void
    {
        $now = Carbon::parse('2026-07-27T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'lesson_batch_size' => 8,
        ]);
        $guruCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 7],
            'due_at' => $now->copy()->addDay(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 365],
            'due_at' => $now->copy()->addYear(),
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Relearning, [
            'scheduler_state' => ['stability' => 365],
            'due_at' => $now->copy()->addMinutes(10),
        ]);

        for ($review = 0; $review < 30; $review++) {
            CardReviewEvent::factory()->for($guruCard, 'card')->create([
                'rating' => $review < 10 ? CardReviewRating::Again : CardReviewRating::Good,
                'reviewed_at' => $now->copy()->subMinutes($review),
            ]);
        }

        $overview = app(GetStudyOverviewAction::class)->handle(userId: $user->id, now: $now);

        $this->assertSame([
            'apprentice' => 1,
            'guru' => 1,
            'master' => 0,
            'enlightened' => 0,
            'burned' => 1,
        ], $overview['mastery_spread']);
        $this->assertSame('pause', $overview['learning_readiness']['recommendation']);
        $this->assertSame('pause', $overview['learning_readiness']['readiness_level']);
        $this->assertSame(30, $overview['learning_readiness']['sample_size']);
        $this->assertSame(0.667, $overview['learning_readiness']['recent_recall']);
        $this->assertSame(3, $overview['learning_readiness']['suggested_batch_size']);
        $this->assertSame('Reviews first recommended', $overview['learning_readiness']['display_status']);
        $this->assertSame(
            'Recent recall is 67% against a 90% target. 1 Apprentice card needs reinforcement, with 2 reviews projected over seven days.',
            $overview['learning_readiness']['display_summary'],
        );
    }

    public function test_it_reports_separate_n5_vocabulary_and_grammar_mastery_using_the_strongest_linked_card(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $this->seedIncludedCardMasteryFixtures($deck);
        $this->seedExcludedCardMasteryFixtures($user, $deck);
        $this->seedWanikaniMasteryFixtures($user);

        $n5 = app(GetStudyOverviewAction::class)->handle(userId: $user->id)['jlpt_mastery']['N5'];
        $deckN5 = app(GetStudyOverviewAction::class)->handle(userId: $user->id, deckId: $deck->id)['jlpt_mastery']['N5'];
        $n4 = app(GetStudyOverviewAction::class)->handle(userId: $user->id)['jlpt_mastery']['N4'];
        $deckN4 = app(GetStudyOverviewAction::class)->handle(userId: $user->id, deckId: $deck->id)['jlpt_mastery']['N4'];

        $this->assertSame(['mastery_percent' => 0, 'known' => 3, 'known_from_cards' => 2, 'known_from_wanikani' => 2, 'known_from_both' => 1, 'matched' => 2, 'covered' => 2, 'total' => 684], $n5['vocabulary']);
        $this->assertSame(['mastery_percent' => 1, 'known' => 1, 'known_from_cards' => 1, 'known_from_wanikani' => 0, 'known_from_both' => 0, 'matched' => 1, 'covered' => 1, 'total' => 77], $n5['grammar']);
        $this->assertSame(['mastery_percent' => 0, 'known' => 1, 'known_from_cards' => 1, 'known_from_wanikani' => 0, 'known_from_both' => 0, 'matched' => 1, 'covered' => 1, 'total' => 684], $deckN5['vocabulary']);
        $this->assertSame(['mastery_percent' => 1, 'known' => 1, 'known_from_cards' => 1, 'known_from_wanikani' => 0, 'known_from_both' => 0, 'matched' => 1, 'covered' => 1, 'total' => 77], $deckN5['grammar']);
        $this->assertArrayNotHasKey('overall', $n5);
        $this->assertSame(['mastery_percent' => 0, 'known' => 1, 'known_from_cards' => 0, 'known_from_wanikani' => 1, 'known_from_both' => 0, 'matched' => 0, 'covered' => 0, 'total' => 640], $n4['vocabulary']);
        $this->assertSame(['mastery_percent' => 0, 'known' => 0, 'known_from_cards' => 0, 'known_from_wanikani' => 0, 'known_from_both' => 0, 'matched' => 0, 'covered' => 0, 'total' => 89], $n4['grammar']);
        $this->assertSame(['mastery_percent' => 0, 'known' => 0, 'known_from_cards' => 0, 'known_from_wanikani' => 0, 'known_from_both' => 0, 'matched' => 0, 'covered' => 0, 'total' => 640], $deckN4['vocabulary']);
        $this->assertSame(['mastery_percent' => 0, 'known' => 0, 'known_from_cards' => 0, 'known_from_wanikani' => 0, 'known_from_both' => 0, 'matched' => 0, 'covered' => 0, 'total' => 89], $deckN4['grammar']);
        $this->assertArrayNotHasKey('overall', $n4);
    }

    public function test_readiness_uses_recall_and_projected_review_time_instead_of_raw_due_count(): void
    {
        $now = Carbon::parse('2026-08-05T12:00:00Z');
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $settings = StudySettings::factory()->for($user)->create([
            'lesson_batch_size' => 8,
            'review_time_budget_minutes' => 120,
        ]);
        $reviewedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'due_at' => $now->copy()->subDay(),
        ]);

        for ($card = 1; $card < 70; $card++) {
            $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
                'due_at' => $now->copy()->subDay(),
            ]);
        }

        for ($review = 0; $review < 40; $review++) {
            CardReviewEvent::factory()->for($reviewedCard, 'card')->create([
                'rating' => $review < 2 ? CardReviewRating::Again : CardReviewRating::Good,
                'reviewed_at' => $now->copy()->subMinutes($review),
                'duration_ms' => 600_000,
            ]);
        }

        $withinBudget = app(GetStudyOverviewAction::class)->handle(userId: $user->id, now: $now);

        $this->assertSame(70, $withinBudget['learning_readiness']['due_backlog']);
        $this->assertSame(70, $withinBudget['learning_readiness']['projected_seven_day_reviews']);
        $this->assertSame(600.0, $withinBudget['learning_readiness']['median_review_duration_seconds']);
        $this->assertSame(100, $withinBudget['learning_readiness']['projected_daily_review_minutes']);
        $this->assertSame(20, $withinBudget['learning_readiness']['review_time_headroom_minutes']);
        $this->assertSame('ready', $withinBudget['learning_readiness']['readiness_level']);
        $this->assertSame('ready', $withinBudget['learning_readiness']['recommendation']);
        $this->assertSame('Ready to learn', $withinBudget['learning_readiness']['display_status']);
        $this->assertSame(
            'Recent recall is 95% against a 90% target. 70 Apprentice cards need reinforcement, with 70 reviews projected over seven days.',
            $withinBudget['learning_readiness']['display_summary'],
        );

        $settings->review_time_budget_minutes = 110;
        $settings->saveOrFail();

        $nearBudget = app(GetStudyOverviewAction::class)->handle(userId: $user->id, now: $now);

        $this->assertSame('steady', $nearBudget['learning_readiness']['readiness_level']);
        $this->assertSame(8, $nearBudget['learning_readiness']['suggested_batch_size']);

        $settings->review_time_budget_minutes = 90;
        $settings->saveOrFail();

        $overBudget = app(GetStudyOverviewAction::class)->handle(userId: $user->id, now: $now);

        $this->assertSame('ease_up', $overBudget['learning_readiness']['readiness_level']);
        $this->assertSame('caution', $overBudget['learning_readiness']['recommendation']);
        $this->assertSame(-10, $overBudget['learning_readiness']['review_time_headroom_minutes']);
        $this->assertSame('Add carefully', $overBudget['learning_readiness']['display_status']);

        CardReviewEvent::query()->where('card_id', $reviewedCard->id)->update([
            'rating' => CardReviewRating::Good->value,
        ]);
        $settings->review_time_budget_minutes = 130;
        $settings->saveOrFail();

        $strong = app(GetStudyOverviewAction::class)->handle(userId: $user->id, now: $now);

        $this->assertSame(1.0, $strong['learning_readiness']['recent_recall']);
        $this->assertSame(30, $strong['learning_readiness']['review_time_headroom_minutes']);
        $this->assertSame('strong', $strong['learning_readiness']['readiness_level']);
        $this->assertSame(8, $strong['learning_readiness']['suggested_batch_size']);
    }

    public function test_readiness_does_not_claim_strong_capacity_before_timing_is_calibrated(): void
    {
        $now = Carbon::parse('2026-08-05T12:00:00Z');
        $user = User::factory()->create();
        $card = $this->cardWithStudyStatus(
            $this->deckFor($user),
            CardStudyStatus::Review,
            ['due_at' => $now->copy()->addDay()],
        );

        for ($review = 0; $review < 30; $review++) {
            CardReviewEvent::factory()->for($card, 'card')->create([
                'rating' => CardReviewRating::Good,
                'reviewed_at' => $now->copy()->subMinutes($review),
                'duration_ms' => null,
            ]);
        }

        $readiness = app(GetStudyOverviewAction::class)
            ->handle(userId: $user->id, now: $now)['learning_readiness'];

        $this->assertSame(1.0, $readiness['recent_recall']);
        $this->assertNull($readiness['projected_daily_review_minutes']);
        $this->assertSame('ready', $readiness['readiness_level']);
    }

    public function test_lightweight_overview_omits_guidance_for_hot_write_responses(): void
    {
        $user = User::factory()->create();
        $this->cardWithStudyStatus(
            $this->deckFor($user),
            CardStudyStatus::Review,
            ['scheduler_state' => ['stability' => 30]],
        );
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $overview = app(GetStudyOverviewAction::class)->handle(
                userId: $user->id,
                includeGuidance: false,
            );
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertArrayNotHasKey('mastery_spread', $overview);
        $this->assertArrayNotHasKey('jlpt_mastery', $overview);
        $this->assertArrayNotHasKey('learning_readiness', $overview);
        $this->assertCount(2, $queries);
    }

    private function seedIncludedCardMasteryFixtures(Deck $deck): void
    {
        $weakVocabularyCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 7],
        ]);
        $strongVocabularyCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 365],
        ]);
        $grammarCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 90],
        ]);

        foreach ([$weakVocabularyCard, $strongVocabularyCard] as $card) {
            DB::table('card_learning_concepts')->insert([
                'card_id' => $card->id,
                'concept_id' => 'n5-vocab-1198550-2120ff50',
                'match_method' => 'exact',
                'match_source' => 'backfill',
                'confidence' => 1,
                'classifier_version' => 'n5-rules-v1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('card_learning_concepts')->insert([
            'card_id' => $grammarCard->id,
            'concept_id' => 'n5-grammar-arimasu-existence-inanimate',
            'match_method' => 'surface',
            'match_source' => 'backfill',
            'confidence' => 0.7,
            'classifier_version' => 'n5-rules-v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedExcludedCardMasteryFixtures(User $user, Deck $deck): void
    {
        $otherUserCard = $this->cardWithStudyStatus(
            $this->deckFor(User::factory()->create()),
            CardStudyStatus::Review,
            ['scheduler_state' => ['stability' => 365]],
        );
        $deletedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 365],
        ]);
        $deletedDeck = $this->deckFor($user);
        $deletedDeckCard = $this->cardWithStudyStatus($deletedDeck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 365],
        ]);
        $guruCardInOtherActiveDeck = $this->cardWithStudyStatus(
            $this->deckFor($user),
            CardStudyStatus::Review,
            ['scheduler_state' => ['stability' => 7]],
        );
        $lockedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'scheduler_state' => ['stability' => 365],
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        foreach ([$otherUserCard, $deletedCard, $deletedDeckCard, $lockedCard] as $excludedCard) {
            DB::table('card_learning_concepts')->insert([
                'card_id' => $excludedCard->id,
                'concept_id' => 'n5-vocab-1198180-ada066ed',
                'match_method' => 'exact',
                'match_source' => 'backfill',
                'confidence' => 1,
                'classifier_version' => 'n5-rules-v1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('card_learning_concepts')->insert([
            'card_id' => $guruCardInOtherActiveDeck->id,
            'concept_id' => 'n5-vocab-1381380-ebec6584',
            'match_method' => 'exact',
            'match_source' => 'backfill',
            'confidence' => 1,
            'classifier_version' => 'n5-rules-v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deletedCard->delete();
        $deletedDeck->delete();
    }

    private function seedWanikaniMasteryFixtures(User $user): void
    {
        $otherUser = User::factory()->create();
        DB::table('wanikani_subjects')->insert([
            [
                'subject_id' => 9001,
                'subject_type' => 'vocabulary',
                'characters' => '上げる',
                'normalized_key' => '上げる',
                'readings' => json_encode(['あげる'], JSON_THROW_ON_ERROR),
                'meanings' => json_encode(['to raise'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'subject_id' => 9002,
                'subject_type' => 'vocabulary',
                'characters' => '赤',
                'normalized_key' => '赤',
                'readings' => json_encode(['あか'], JSON_THROW_ON_ERROR),
                'meanings' => json_encode(['red'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'subject_id' => 9003,
                'subject_type' => 'vocabulary',
                'characters' => '青',
                'normalized_key' => '青',
                'readings' => json_encode(['あお'], JSON_THROW_ON_ERROR),
                'meanings' => json_encode(['blue'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'subject_id' => 9004,
                'subject_type' => 'vocabulary',
                'characters' => '安心',
                'normalized_key' => '安心',
                'readings' => json_encode(['あんしん'], JSON_THROW_ON_ERROR),
                'meanings' => json_encode(['relief'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('wanikani_subject_learning_concepts')->insert([
            ['subject_id' => 9001, 'concept_id' => 'n5-vocab-1198550-2120ff50', 'match_method' => 'expression', 'confidence' => 1, 'matcher_version' => 'test', 'created_at' => now(), 'updated_at' => now()],
            ['subject_id' => 9002, 'concept_id' => 'n5-vocab-1198180-ada066ed', 'match_method' => 'expression', 'confidence' => 1, 'matcher_version' => 'test', 'created_at' => now(), 'updated_at' => now()],
            ['subject_id' => 9003, 'concept_id' => 'n5-vocab-1275320-9949d874', 'match_method' => 'expression', 'confidence' => 1, 'matcher_version' => 'test', 'created_at' => now(), 'updated_at' => now()],
            ['subject_id' => 9004, 'concept_id' => 'n4-vocab-1153890-afd1a981', 'match_method' => 'expression', 'confidence' => 1, 'matcher_version' => 'test', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('user_wanikani_assignments')->insert([
            ['user_id' => $user->id, 'subject_id' => 9001, 'srs_stage' => 5, 'passed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'subject_id' => 9002, 'srs_stage' => 7, 'passed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $otherUser->id, 'subject_id' => 9003, 'srs_stage' => 9, 'passed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'subject_id' => 9004, 'srs_stage' => 5, 'passed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
