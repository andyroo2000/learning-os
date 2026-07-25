<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class BuildStudyOfflineReserveApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/study/offline-reserve')->assertUnauthorized();
    }

    public function test_it_returns_full_card_contracts_for_offline_storage(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $card = $this->cardWithStudyStatus($this->deckFor($user), CardStudyStatus::New, [
            'new_queue_position' => 1,
            'prompt_json' => ['type' => 'recognition', 'text' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);

        $response = $this->postJson('/api/study/offline-reserve');

        $response->assertOk()
            ->assertJsonPath('reserveDays', 5)
            ->assertJsonPath('cards.0.id', $card->clientId())
            ->assertJsonPath('cards.0.syncId', $card->id)
            ->assertJsonPath('cards.0.prompt.text', '会社')
            ->assertJsonPath('cards.0.answer.meaning', 'company')
            ->assertJsonStructure([
                'generatedAt',
                'horizonEndsAt',
                'cards' => [[
                    'id',
                    'syncId',
                    'cardType',
                    'prompt',
                    'answer',
                    'state',
                ]],
            ]);
    }
}
