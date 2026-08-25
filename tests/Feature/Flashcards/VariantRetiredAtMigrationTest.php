<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VariantRetiredAtMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_backfills_only_completed_owned_families(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstRetired = Card::factory()->for($deck)->create([
            'variant_group_id' => 'completed-family',
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Locked->value,
            'study_status' => CardStudyStatus::Suspended->value,
        ]);
        $secondRetired = Card::factory()->for($deck)->create([
            'variant_group_id' => 'completed-family',
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked->value,
            'study_status' => CardStudyStatus::Suspended->value,
        ]);
        Card::factory()->for($deck)->create([
            'variant_group_id' => 'completed-family',
            'variant_stage' => 3,
            'variant_status' => VocabVariantStatus::Available->value,
        ]);

        $notFinished = Card::factory()->for($deck)->create([
            'variant_group_id' => 'unfinished-family',
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Locked->value,
            'study_status' => CardStudyStatus::Suspended->value,
        ]);
        Card::factory()->for($deck)->create([
            'variant_group_id' => 'unfinished-family',
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Available->value,
        ]);
        Card::factory()->for($deck)->create([
            'variant_group_id' => 'unfinished-family',
            'variant_stage' => 3,
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        $ownerIsolated = Card::factory()->for($deck)->create([
            'variant_group_id' => 'cross-owner-family',
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Locked->value,
            'study_status' => CardStudyStatus::Suspended->value,
        ]);
        Card::factory()->for($this->deckFor(User::factory()->create()))->create([
            'variant_group_id' => 'cross-owner-family',
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Available->value,
        ]);

        Schema::table('cards', fn ($table) => $table->dropColumn('variant_retired_at'));

        $migration = require database_path(
            'migrations/2026_08_25_060000_add_variant_retired_at_to_cards_table.php',
        );
        $migration->up();

        $this->assertNotNull(DB::table('cards')->where('id', $firstRetired->id)->value('variant_retired_at'));
        $this->assertNotNull(DB::table('cards')->where('id', $secondRetired->id)->value('variant_retired_at'));
        $this->assertNull(DB::table('cards')->where('id', $notFinished->id)->value('variant_retired_at'));
        $this->assertNull(DB::table('cards')->where('id', $ownerIsolated->id)->value('variant_retired_at'));
    }
}
