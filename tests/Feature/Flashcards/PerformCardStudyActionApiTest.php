<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Models\StudySettings;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PerformCardStudyActionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_actions_require_authentication(): void
    {
        $card = Card::factory()->create();

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'set_due',
            'mode' => 'now',
        ])->assertUnauthorized();
    }

    public function test_it_sets_a_custom_due_date_and_returns_card_plus_overview(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $user = $this->signIn();
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 20,
            ]);
            $card = $this->cardFor($user, [
                'study_status' => CardStudyStatus::New,
                'new_queue_position' => 1,
            ]);

            $response = $this->postJson("/api/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'due_at' => '2026-06-05T14:15:00Z',
                'time_zone' => 'America/New_York',
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('data.card.id', $card->id)
                ->assertJsonPath('data.card.study_status', 'review')
                ->assertJsonPath('data.card.new_queue_position', null)
                ->assertJsonPath('data.card.due_at', '2026-06-05T14:15:00.000000Z')
                ->assertJsonPath('data.overview.review_count', 1)
                ->assertJsonPath('data.overview.new_count', 0);

            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'study_status' => 'review',
                'new_queue_position' => null,
                'due_at' => '2026-06-05 14:15:00',
            ]);
            $this->assertSame($card->id, SyncFeedEntry::query()->sole()->resource_id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_normalizes_action_and_mode_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $card = $this->cardFor($this->signIn(), [
                'study_status' => CardStudyStatus::Review,
                'due_at' => '2026-06-05T12:00:00Z',
            ]);

            $this->postJson("/api/cards/{$card->id}/actions", [
                'action' => '  SET_DUE  ',
                'mode' => '  NOW  ',
            ])
                ->assertOk()
                ->assertJsonPath('data.card.due_at', '2026-06-04T12:00:00.000000Z');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_tomorrow_mode_requires_a_valid_timezone(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'set_due',
            'mode' => 'tomorrow',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['time_zone']);

        $this->postJson("/api/cards/{$card->id}/actions", [
            'action' => 'set_due',
            'mode' => 'tomorrow',
            'time_zone' => ['America/New_York'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['time_zone']);
    }

    public function test_custom_date_requires_a_strict_iso_datetime_within_ten_years(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $card = $this->cardFor($this->signIn());

            $this->postJson("/api/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'due_at' => 'tomorrow',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['due_at']);

            $this->postJson("/api/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'due_at' => ['2026-06-05T14:15:00Z'],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['due_at']);

            $this->postJson("/api/cards/{$card->id}/actions", [
                'action' => 'set_due',
                'mode' => 'custom_date',
                'due_at' => '2037-06-04T12:00:00Z',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['due_at']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_hides_another_users_card_before_validating_payload(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create([
            'study_status' => CardStudyStatus::Review,
        ]);

        $this->postJson("/api/cards/{$otherCard->id}/actions", [
            'action' => 'not-real',
            'mode' => ['tomorrow'],
        ])->assertNotFound();
    }
}
