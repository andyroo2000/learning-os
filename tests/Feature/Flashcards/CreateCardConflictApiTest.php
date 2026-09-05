<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardConflictApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_client_provided_ulid_conflicts(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'salve',
            'back_text' => 'hello',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_id_conflict');

        $this->assertDatabaseCount('cards', 1);
    }

    public function test_it_rejects_client_provided_ulid_variant_metadata_conflicts(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => 'vocab-group-1',
            'variant_sentence_id' => 'sentence-1',
            'variant_kind' => VocabVariantKind::SentenceCloze,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Available,
            'variant_unlocked_at' => '2026-06-04T08:45:30.000000Z',
        ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => 'vocab-group-2',
            'variant_sentence_id' => 'sentence-1',
            'variant_kind' => VocabVariantKind::SentenceCloze->value,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Available->value,
            'variant_unlocked_at' => '2026-06-04T08:45:30.000000Z',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_id_conflict');

        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'variant_group_id' => 'vocab-group-1',
        ]);
        $this->assertDatabaseCount('cards', 1);
    }

    public function test_it_rejects_same_user_cross_deck_ulid_conflicts(): void
    {
        $user = $this->signIn();
        $sourceDeck = $this->deckFor($user);
        $targetDeck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($sourceDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $targetDeck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_id_conflict');

        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $sourceDeck->id,
        ]);
        $this->assertDatabaseMissing('cards', [
            'id' => $id,
            'deck_id' => $targetDeck->id,
        ]);
    }

    public function test_it_rejects_same_user_conflicts_when_concurrent_create_wins_the_race(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());
        $inserted = false;

        $createCard = new CreateCardAction(
            recordSyncFeedEntry: app(RecordSyncFeedEntryAction::class),
            afterClientIdPrecheckMiss: function (CreateCardData $data) use (&$inserted, $deck): void {
                if ($inserted || $data->id === null) {
                    return;
                }

                $inserted = true;

                DB::table('cards')->insert([
                    'id' => $data->id,
                    'deck_id' => $deck->id,
                    'front_text' => 'salve',
                    'back_text' => 'hello',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            },
        );

        $this->app->instance(CreateCardAction::class, $createCard);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Card ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_id_conflict');

        $this->assertTrue($inserted);
        $this->assertDatabaseHas('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'salve',
            'back_text' => 'hello',
        ]);
        $this->assertDatabaseCount('cards', 1);
    }
}
