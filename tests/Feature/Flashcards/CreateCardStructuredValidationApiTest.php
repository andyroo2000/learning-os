<?php

namespace Tests\Feature\Flashcards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCardStructuredValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_non_array_structured_content(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'prompt_json' => 'What is ATP?',
            'answer_json' => 'Cellular energy currency.',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt_json', 'answer_json']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_invalid_variant_metadata(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => '犬',
            'back_text' => 'dog',
            'variant_group_id' => str_repeat('a', 65),
            'variant_sentence_id' => ['sentence-1'],
            'variant_kind' => 'sentence-audio-recognition',
            'variant_stage' => 0,
            'variant_status' => ['available'],
            'variant_unlocked_at' => 'yesterday',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'variant_group_id',
                'variant_sentence_id',
                'variant_kind',
                'variant_stage',
                'variant_status',
                'variant_unlocked_at',
            ]);

        $this->assertDatabaseCount('cards', 0);

        $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => '犬',
            'back_text' => 'dog',
            'variant_unlocked_at' => 1234567890,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variant_unlocked_at']);

        $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => '犬',
            'back_text' => 'dog',
            'variant_unlocked_at' => '2026-06-04T14:15:30',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variant_unlocked_at']);

        foreach ([
            '2026-02-31T14:15:30Z',
            '2026-06-04T14:15:30+15:00',
            '2026-06-04T14:15:30-13:00',
        ] as $variantUnlockedAt) {
            $this->postJson('/api/cards', [
                'deck_id' => $deck->id,
                'front_text' => '犬',
                'back_text' => 'dog',
                'variant_unlocked_at' => $variantUnlockedAt,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['variant_unlocked_at']);
        }

        $this->assertDatabaseCount('cards', 0);
    }
}
