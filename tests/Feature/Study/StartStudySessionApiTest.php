<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Models\StudySettings;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
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

    public function test_start_filters_ready_cards_by_deck_id(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $targetDeckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $this->postJson('/api/study/session/start', [
            'deck_id' => $deck->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.overview.new_count', 1)
            ->assertJsonPath('data.overview.total_cards', 1)
            ->assertJsonPath('data.cards.0.id', $targetDeckCard->id)
            ->assertJsonCount(1, 'data.cards');
    }

    public function test_start_normalizes_deck_id_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);

        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 20,
        ]);
        $targetDeckCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $this->postJson('/api/study/session/start', [
            'deck_id' => '  '.strtoupper($deck->id).'  ',
        ])
            ->assertOk()
            ->assertJsonPath('data.overview.new_count', 1)
            ->assertJsonPath('data.cards.0.id', $targetDeckCard->id)
            ->assertJsonCount(1, 'data.cards');
    }

    public function test_start_returns_empty_session_for_another_users_deck_id(): void
    {
        $user = $this->signIn();
        StudySettings::factory()->for($user)->create();
        $otherDeck = $this->deckFor(User::factory()->create());
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'due_at' => Carbon::parse('2026-06-04T11:00:00Z'),
        ]);

        $this->postJson('/api/study/session/start', [
            'deck_id' => $otherDeck->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.overview.due_count', 0)
            ->assertJsonPath('data.overview.total_cards', 0)
            ->assertJsonCount(0, 'data.cards');
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

    public function test_start_rejects_malformed_deck_id_filters(): void
    {
        $this->signIn();

        $this->postJson('/api/study/session/start', [
            'deck_id' => 'not-a-ulid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->postJson('/api/study/session/start', [
            'deck_id' => ['01J00000000000000000000000'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_start_rejects_blank_deck_id_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        $this->signIn();

        $this->postJson('/api/study/session/start', [
            'deck_id' => '   ',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
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

    public function test_start_returns_ready_failed_cards_before_new_cards(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 20,
            ]);
            $readyFailedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Relearning, [
                'due_at' => Carbon::parse('2026-06-04T11:50:00Z'),
                'failed_at' => Carbon::parse('2026-06-04T11:00:00Z'),
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 1,
            ]);

            $this->postJson('/api/study/session/start')
                ->assertOk()
                ->assertJsonPath('data.overview.due_count', 0)
                ->assertJsonPath('data.overview.failed_count', 1)
                ->assertJsonPath('data.overview.new_cards_available_today', 0)
                ->assertJsonMissingPath('data.overview.failed_due_count')
                ->assertJsonPath('data.cards.0.id', $readyFailedCard->id)
                ->assertJsonCount(1, 'data.cards');
        } finally {
            Carbon::setTestNow();
        }
    }
}
