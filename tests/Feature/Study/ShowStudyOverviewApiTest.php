<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Models\StudySettings;
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
}
