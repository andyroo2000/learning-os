<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Carbon;

class UpdateCardIdempotencyApiTest extends UpdateCardApiTestCase
{
    public function test_it_preserves_structured_content_when_omitted(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'prompt_json' => ['type' => 'text', 'text' => 'What is ATP?'],
            'answer_json' => ['type' => 'text', 'text' => 'Cellular energy currency.'],
        ]);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.prompt_json.type', 'text')
            ->assertJsonPath('data.prompt_json.text', 'What is ATP?')
            ->assertJsonPath('data.answer_json.type', 'text')
            ->assertJsonPath('data.answer_json.text', 'Cellular energy currency.');

        $card->refresh();

        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $card->prompt_json);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $card->answer_json);
    }

    public function test_it_preserves_variant_metadata_when_fields_are_omitted(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => 'keep-group',
            'variant_sentence_id' => 'keep-sentence',
            'variant_kind' => VocabVariantKind::SentenceCloze,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Available,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);

        $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '会社',
            'back_text' => 'company',
        ])->assertOk();

        $card->refresh();
        $this->assertSame('keep-group', $card->variant_group_id);
        $this->assertSame('keep-sentence', $card->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceCloze->value, $card->variant_kind);
        $this->assertSame(2, $card->variant_stage);
        $this->assertSame(VocabVariantStatus::Available->value, $card->variant_status);
        $this->assertSame('2026-06-05T14:15:00.000000Z', $card->variant_unlocked_at?->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_is_idempotent_when_text_is_unchanged(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $card->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', CardResource::make($card)->resolve()['updated_at']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => 'recognition',
            'updated_at' => $timestamp,
        ]);
    }

    public function test_it_is_idempotent_when_trimmed_text_matches_existing_values(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '  ciao  ',
            'back_text' => '  hello  ',
        ]);

        $card->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', CardResource::make($card)->resolve()['updated_at']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => 'recognition',
            'updated_at' => $timestamp,
        ]);
    }

    public function test_it_is_idempotent_when_variant_metadata_matches_existing_values(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => 'keep-group',
            'variant_sentence_id' => 'keep-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $card->refresh();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->putJson("/api/cards/{$card->id}", [
                'front_text' => '会社',
                'back_text' => 'company',
                'variant_group_id' => ' keep-group ',
                'variant_sentence_id' => ' keep-sentence ',
                'variant_kind' => ' SENTENCE_AUDIO_RECOGNITION ',
                'variant_stage' => ' 2 ',
                'variant_status' => ' LOCKED ',
                'variant_unlocked_at' => '2026-06-05T14:15:00.987654Z',
            ]);

        $card->refresh();

        // UpdateCardAction compares variant_unlocked_at at persisted second precision.
        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', CardResource::make($card)->resolve()['updated_at'])
            ->assertJsonPath('data.variant_unlocked_at', '2026-06-05T14:15:00.000000Z');

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'updated_at' => $timestamp,
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_updates_timestamp_when_text_changes(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $card->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', CardResource::make($card)->resolve()['updated_at']);

        $this->assertTrue($card->updated_at->isAfter($timestamp));
        $this->assertNotSame($timestamp->toJSON(), $response->json('data.updated_at'));

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);
    }
}
