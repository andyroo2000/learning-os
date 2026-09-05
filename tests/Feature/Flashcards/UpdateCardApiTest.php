<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
use App\Models\User;

class UpdateCardApiTest extends UpdateCardApiTestCase
{
    public function test_it_updates_an_owned_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonPath('data.deck_id', $card->deck_id)
            ->assertJsonPath('data.front_text', 'arrivederci')
            ->assertJsonPath('data.back_text', 'goodbye')
            ->assertJsonPath('data.card_type', 'recognition')
            ->assertJsonPath('data.prompt_json', null)
            ->assertJsonPath('data.answer_json', null)
            ->assertJsonPath('data.content_revision', 1)
            ->assertJsonPath('data.search_text', 'arrivederci goodbye')
            ->assertJsonMissingPath('data.media_assets')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'deck_id',
                    'course_id',
                    'front_text',
                    'back_text',
                    'card_type',
                    'prompt_json',
                    'answer_json',
                    'content_revision',
                    'search_text',
                    'study_status',
                    'new_queue_position',
                    'scheduler_state',
                    'due_at',
                    'introduced_at',
                    'failed_at',
                    'last_reviewed_at',
                    'created_at',
                    'updated_at',
                    'deleted_at',
                ],
            ]);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'deck_id' => $card->deck_id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => 'recognition',
            'prompt_json' => null,
            'answer_json' => null,
            'search_text' => 'arrivederci goodbye',
        ]);
    }

    public function test_it_rejects_a_stale_content_revision_and_returns_the_current_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'front_text' => 'company',
            'back_text' => '会社',
        ]);

        $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'school',
            'back_text' => '学校',
            'expected_content_revision' => 0,
        ])->assertOk()->assertJsonPath('data.content_revision', 1);

        $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'dog',
            'back_text' => '犬',
            'expected_content_revision' => 0,
        ])
            ->assertConflict()
            ->assertJsonPath('code', 'card_revision_conflict')
            ->assertJsonPath('data.content_revision', 1)
            ->assertJsonPath('data.front_text', 'school')
            ->assertJsonPath('data.back_text', '学校');

        $this->assertSame('school', $card->refresh()->front_text);
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_it_validates_the_expected_content_revision(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        foreach ([-1, 'not-an-integer', ['0']] as $invalidRevision) {
            $this->putJson("/api/cards/{$card->id}", [
                ...$this->cardUpdatePayload('updated front'),
                'expected_content_revision' => $invalidRevision,
            ])->assertJsonValidationErrors(['expected_content_revision']);
        }
    }

    public function test_it_normalizes_text_inputs(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '  arrivederci  ',
            'back_text' => '  goodbye  ',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.front_text', 'arrivederci')
            ->assertJsonPath('data.back_text', 'goodbye');
    }

    public function test_update_is_rate_limited_by_user(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'front_text' => 'original user front',
            'back_text' => 'original user back',
        ]);
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser, [
            'front_text' => 'original other front',
            'back_text' => 'original other back',
        ]);

        $this->withStudyCardRateLimitOverride(
            StudyCardUpdateRateLimiter::NAME,
            [$user->id, $otherUser->id],
            function () use ($card, $otherCard, $otherUser, $user): void {
                foreach ([1, 2] as $attempt) {
                    $this
                        ->putJson("/api/cards/{$card->id}", $this->cardUpdatePayload("user front {$attempt}"))
                        ->assertOk();
                }

                $this->signIn($otherUser);

                $this
                    ->putJson("/api/cards/{$otherCard->id}", $this->cardUpdatePayload('other front'))
                    ->assertOk();

                $this->signIn($user);

                $this
                    ->putJson("/api/cards/{$card->id}", $this->cardUpdatePayload('blocked front'))
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->getJson("/api/cards/{$card->id}")
                    ->assertOk()
                    ->assertJsonPath('data.front_text', 'user front 2');

                $this->assertSame('user front 2', $card->refresh()->front_text);
                $this->assertSame('other front', $otherCard->refresh()->front_text);
            },
        );
    }

    public function test_it_ignores_client_provided_study_state(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'study_status' => 'review',
            'new_queue_position' => 99,
            'scheduler_state' => ['state' => 2],
            'due_at' => '2026-06-05T14:15:00Z',
            'introduced_at' => '2026-06-01T14:15:00Z',
            'failed_at' => '2026-06-02T14:15:00Z',
            'last_reviewed_at' => '2026-06-03T14:15:00Z',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.study_status', 'new')
            ->assertJsonPath('data.new_queue_position', $card->new_queue_position)
            ->assertJsonPath('data.scheduler_state', null)
            ->assertJsonPath('data.due_at', null)
            ->assertJsonPath('data.introduced_at', null)
            ->assertJsonPath('data.failed_at', null)
            ->assertJsonPath('data.last_reviewed_at', null);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'study_status' => 'new',
            'new_queue_position' => $card->new_queue_position,
            'scheduler_state' => null,
            'due_at' => null,
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => null,
        ]);
    }

    /**
     * @return array{front_text: string, back_text: string}
     */
    private function cardUpdatePayload(string $frontText): array
    {
        return [
            'front_text' => $frontText,
            'back_text' => 'back '.$frontText,
        ];
    }
}
