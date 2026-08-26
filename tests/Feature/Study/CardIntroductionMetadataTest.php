<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Enums\CardSourceKind;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Models\CardIntroductionCohort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CardIntroductionMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_introduction_tables_and_queue_index_are_present(): void
    {
        $this->assertTrue(Schema::hasColumns('card_introduction_cohorts', [
            'id',
            'user_id',
            'source_kind',
            'label',
            'source_reference',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('cards', [
            'introduction_cohort_id',
            'selection_policy',
            'priority_until',
            'introduction_available_at',
        ]));
        $this->assertTrue(Schema::hasIndex('cards', 'cards_new_lane_queue_idx'));
        $this->assertTrue(Schema::hasIndex('cards', 'cards_new_availability_queue_idx'));
    }

    public function test_cohort_source_and_card_policy_use_backed_enums(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $cohort = new CardIntroductionCohort;
        $cohort->user_id = $user->id;
        $cohort->source_kind = CardSourceKind::LessonFollowup;
        $cohort->label = 'iTalki · August 25';
        $cohort->source_reference = 'lesson-2026-08-25';
        $cohort->saveOrFail();
        $priorityUntil = Carbon::parse('2026-09-01T18:30:00Z');

        $card = Card::factory()->for($deck)->create([
            'introduction_cohort_id' => $cohort->id,
            'selection_policy' => CardSelectionPolicy::ReviewSoon,
            'priority_until' => $priorityUntil,
        ])->refresh();

        $this->assertSame(CardSourceKind::LessonFollowup, $cohort->refresh()->source_kind);
        $this->assertSame(CardSelectionPolicy::ReviewSoon, $card->selection_policy);
        $this->assertTrue($priorityUntil->equalTo($card->priority_until));
        $this->assertTrue($cohort->is($card->introductionCohort));
    }

    public function test_deleting_a_cohort_preserves_cards_and_clears_the_reference(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $cohort = new CardIntroductionCohort;
        $cohort->user_id = $user->id;
        $cohort->source_kind = CardSourceKind::WaniKani;
        $cohort->saveOrFail();
        $card = Card::factory()->for($deck)->create([
            'introduction_cohort_id' => $cohort->id,
        ]);

        $cohort->delete();

        $this->assertNull($card->refresh()->introduction_cohort_id);
    }

    public function test_existing_import_source_kind_remains_raw_provenance(): void
    {
        $card = Card::factory()->create(['source_kind' => 'anki_import'])->refresh();

        $this->assertSame('anki_import', $card->source_kind);
        $this->assertSame(CardSelectionPolicy::Standard, $card->selection_policy);
    }
}
