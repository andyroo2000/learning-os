<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Models\StudySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class StartStudySessionApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_start_requires_authentication(): void
    {
        $this->postJson('/api/study/session/start')->assertUnauthorized();
    }

    public function test_start_returns_overview_and_ready_cards_without_trusting_client_limits(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 2,
        ]);
        $firstNewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondNewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 3,
        ]);

        $response = $this->postJson('/api/study/session/start', [
            'limit' => 999,
            'time_zone' => 'America/New_York',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.overview.new_cards_per_day', 2)
            ->assertJsonPath('data.overview.new_cards_available_today', 2)
            ->assertJsonPath('data.cards.0.id', $firstNewCard->id)
            ->assertJsonPath('data.cards.1.id', $secondNewCard->id)
            ->assertJsonCount(2, 'data.cards');
    }

    public function test_start_validates_time_zone_without_coercing_malformed_values(): void
    {
        $this->signIn();

        $this->postJson('/api/study/session/start', [
            'time_zone' => 'Not/A_Zone',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['time_zone']);

        $this->postJson('/api/study/session/start', [
            'time_zone' => ['America/New_York'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['time_zone']);
    }

    public function test_start_uses_the_requested_time_zone_for_daily_new_card_allowance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T03:00:00Z'));

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 2,
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
                'introduced_at' => Carbon::parse('2026-06-03T05:00:00Z'),
                'due_at' => Carbon::parse('2026-06-05T00:00:00Z'),
            ]);
            $newCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 1,
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 2,
            ]);

            $this->postJson('/api/study/session/start', [
                'time_zone' => 'America/New_York',
            ])
                ->assertOk()
                ->assertJsonPath('data.overview.new_cards_introduced_today', 1)
                ->assertJsonPath('data.overview.new_cards_available_today', 1)
                ->assertJsonPath('data.cards.0.id', $newCard->id)
                ->assertJsonCount(1, 'data.cards');
        } finally {
            Carbon::setTestNow();
        }
    }
}
