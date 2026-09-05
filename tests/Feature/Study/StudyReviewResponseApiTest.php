<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Actions\GetStudyOverviewAction;
use App\Domain\Study\Models\StudySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class StudyReviewResponseApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_it_records_native_cards_with_null_note_id_and_nullable_duration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $card = $this->cardFor($this->signIn(), [
                'source_note_id' => null,
                'study_status' => CardStudyStatus::Review,
                'due_at' => '2026-06-05T12:00:00Z',
            ]);

            $response = $this->postJson('/api/study/reviews', [
                'cardId' => $card->id,
                'grade' => 'good',
                'durationMs' => null,
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('card.noteId', null)
                ->assertJsonPath('card.state.source.noteId', null);

            $this->assertStudyCardSummaryCompatibilityPayloadHasShape($response->json('card'), 'native review card payload');

            $this->assertDatabaseHas('card_review_events', [
                'id' => $response->json('reviewLogId'),
                'duration_ms' => null,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_records_zero_duration_when_provided(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $card = $this->cardFor($this->signIn(), [
                'study_status' => CardStudyStatus::Review,
                'due_at' => '2026-06-05T12:00:00Z',
            ]);

            $response = $this->postJson('/api/study/reviews', [
                'cardId' => $card->id,
                'grade' => 'good',
                'durationMs' => 0,
            ]);

            $response->assertOk();

            $this->assertDatabaseHas('card_review_events', [
                'id' => $response->json('reviewLogId'),
                'duration_ms' => 0,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_treats_omitted_and_null_time_zone_as_default_overview_timezone(): void
    {
        $user = $this->signIn();
        $firstCard = $this->cardFor($user);
        $secondCard = $this->cardFor($user);
        $getStudyOverview = new class extends GetStudyOverviewAction
        {
            /** @var list<string|null> */
            public array $timeZones = [];

            public function __construct() {}

            /**
             * @return array<string, mixed>
             */
            public function handle(
                int $userId,
                ?string $timeZone = null,
                ?Carbon $now = null,
                ?string $deckId = null,
                ?string $courseId = null,
                bool $includeGuidance = true,
            ): array {
                $this->timeZones[] = $timeZone;

                return [
                    'due_count' => 0,
                    'failed_count' => 0,
                    'new_count' => 0,
                    'new_cards_per_day' => 0,
                    'new_cards_introduced_today' => 0,
                    'new_cards_available_today' => 0,
                    'learning_count' => 0,
                    'review_count' => 0,
                    'suspended_count' => 0,
                    'total_cards' => 0,
                    'latest_import' => null,
                    'next_due_at' => null,
                ];
            }
        };
        $this->app->instance(GetStudyOverviewAction::class, $getStudyOverview);

        $firstResponse = $this->postJson('/api/study/reviews', [
            'cardId' => $firstCard->id,
            'grade' => 'good',
        ]);

        $secondResponse = $this->postJson('/api/study/reviews', [
            'cardId' => $secondCard->id,
            'grade' => 'good',
            'timeZone' => null,
        ]);

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertSame([null, null], $getStudyOverview->timeZones);
        $this->assertDatabaseHas('card_review_events', [
            'id' => $firstResponse->json('reviewLogId'),
            'duration_ms' => null,
        ]);
    }

    public function test_review_response_overview_preserves_study_scope_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05T15:30:00Z'));

        try {
            $user = $this->signIn();
            StudySettings::factory()->for($user)->create([
                'new_cards_per_day' => 20,
            ]);
            $course = Course::factory()->for($user)->create();
            $deck = $this->deckFor($user, ['course_id' => $course->id]);
            $scopedCard = Card::factory()->for($deck)->create([
                'study_status' => CardStudyStatus::New,
                'new_queue_position' => 1,
            ]);
            $otherCourse = Course::factory()->for($user)->create();
            $otherCourseDeck = $this->deckFor($user, ['course_id' => $otherCourse->id]);
            Card::factory()->for($otherCourseDeck)->create([
                'study_status' => CardStudyStatus::New,
                'new_queue_position' => 2,
            ]);

            $response = $this->postJson('/api/study/reviews', [
                'cardId' => $scopedCard->id,
                'grade' => 'good',
                'timeZone' => 'America/New_York',
                'courseId' => strtoupper($course->id),
                'currentOverview' => [
                    'newCount' => 99,
                ],
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('card.id', $scopedCard->id)
                ->assertJsonPath('overview.newCount', 0)
                ->assertJsonPath('overview.learningCount', 1)
                ->assertJsonPath('overview.reviewCount', 0)
                ->assertJsonPath('overview.totalCards', 1)
                ->assertJsonPath('overview.newCardsPerDay', 20);
        } finally {
            Carbon::setTestNow();
        }
    }
}
