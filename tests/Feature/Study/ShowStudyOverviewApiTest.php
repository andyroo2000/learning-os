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

class ShowStudyOverviewApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/study/overview')->assertUnauthorized();
    }

    public function test_show_returns_overview_for_the_authenticated_user(): void
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
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 1,
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 2,
            ]);

            $this->getJson('/api/study/overview?time_zone=America/New_York')
                ->assertOk()
                ->assertJsonPath('data.new_cards_per_day', 2)
                ->assertJsonPath('data.new_cards_introduced_today', 1)
                ->assertJsonPath('data.new_cards_available_today', 1)
                ->assertJsonPath('data.total_cards', 3);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_show_filters_overview_by_deck_id(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);
            $otherDeck = $this->deckFor($user);
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 2,
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 1,
            ]);
            $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
                'new_queue_position' => 2,
            ]);
            $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
                'introduced_at' => Carbon::parse('2026-06-04T11:00:00Z'),
                'due_at' => Carbon::parse('2026-06-05T00:00:00Z'),
            ]);
            $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
                'due_at' => Carbon::parse('2026-06-04T11:00:00Z'),
            ]);

            $this->getJson("/api/study/overview?deck_id={$deck->id}&time_zone=UTC")
                ->assertOk()
                ->assertJsonPath('data.due_count', 0)
                ->assertJsonPath('data.new_count', 2)
                ->assertJsonPath('data.new_cards_introduced_today', 1)
                ->assertJsonPath('data.new_cards_available_today', 1)
                ->assertJsonPath('data.total_cards', 2);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_show_normalizes_deck_id_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);

        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        StudySettings::factory()->for($user)->create();
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $this->getJson('/api/study/overview?deck_id=%20'.strtoupper($deck->id).'%20')
            ->assertOk()
            ->assertJsonPath('data.new_count', 1)
            ->assertJsonPath('data.total_cards', 1);
    }

    public function test_show_returns_empty_overview_for_another_users_deck_id(): void
    {
        $user = $this->signIn();
        StudySettings::factory()->for($user)->create();
        $otherDeck = $this->deckFor(User::factory()->create());
        $this->cardWithStudyStatus($otherDeck, CardStudyStatus::Review, [
            'due_at' => Carbon::parse('2026-06-04T11:00:00Z'),
        ]);

        $this->getJson("/api/study/overview?deck_id={$otherDeck->id}")
            ->assertOk()
            ->assertJsonPath('data.due_count', 0)
            ->assertJsonPath('data.new_count', 0)
            ->assertJsonPath('data.total_cards', 0);
    }

    public function test_show_validates_time_zone_without_coercing_malformed_values(): void
    {
        $this->signIn();

        $this->getJson('/api/study/overview?time_zone=Not%2FA_Zone')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['time_zone']);

        $this->getJson('/api/study/overview?time_zone[]=America%2FNew_York')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['time_zone']);
    }

    public function test_show_rejects_malformed_deck_id_filters(): void
    {
        $this->signIn();

        $this->getJson('/api/study/overview?deck_id=not-a-ulid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->getJson('/api/study/overview?deck_id[]=01J00000000000000000000000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }

    public function test_show_rejects_blank_deck_id_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        $this->signIn();

        $this->getJson('/api/study/overview?deck_id=%20%20%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);
    }
}
