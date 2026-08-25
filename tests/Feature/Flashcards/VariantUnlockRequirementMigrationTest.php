<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VariantUnlockRequirementMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_nullable_requirement_column_preserves_legacy_cards_across_rollback_and_reapply(): void
    {
        $legacyCard = Card::factory()->create();
        $migration = require database_path(
            'migrations/2026_08_25_070000_add_variant_unlock_requirement_to_cards_table.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasColumn('cards', 'variant_unlock_requirement'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('cards', 'variant_unlock_requirement'));
        $this->assertNull(
            Card::query()->findOrFail($legacyCard->id)->variant_unlock_requirement,
        );
    }
}
