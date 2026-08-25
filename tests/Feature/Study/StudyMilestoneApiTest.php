<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Enums\StudyMilestoneKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyMilestoneApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluation_uses_server_card_state_and_presentation_is_idempotent(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $cards = Card::factory()->for($deck)->count(100)->create([
            'study_status' => CardStudyStatus::Review,
            'scheduler_state' => ['stability' => 364.9],
        ]);
        $this->actingAs($user);

        $this->postJson('/api/study/milestones/evaluate')
            ->assertOk()
            ->assertExactJson(['milestones' => [], 'pendingMilestones' => []]);

        $crossingCard = $cards->last();
        $this->assertInstanceOf(Card::class, $crossingCard);
        $crossingCard->scheduler_state = ['stability' => 365];
        $crossingCard->save();
        Card::query()->whereKeyNot($crossingCard->getKey())->update([
            'scheduler_state' => json_encode(['stability' => 365], JSON_THROW_ON_ERROR),
        ]);

        $response = $this->postJson('/api/study/milestones/evaluate')
            ->assertOk()
            ->assertJsonPath('milestones.0.id', StudyMilestoneKey::Burned100->value)
            ->assertJsonPath('milestones.0.presentedAt', null)
            ->assertJsonPath('pendingMilestones.0.id', StudyMilestoneKey::Burned100->value);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $response->json('milestones.0.earnedAt'),
        );

        $this->postJson('/api/study/milestones/evaluate')
            ->assertOk()
            ->assertJsonCount(1, 'milestones')
            ->assertJsonCount(1, 'pendingMilestones');

        $this->postJson('/api/study/milestones/present', [
            'milestoneIds' => [StudyMilestoneKey::Burned100->value],
        ])->assertNoContent();
        $this->postJson('/api/study/milestones/present', [
            'milestoneIds' => [StudyMilestoneKey::Burned100->value],
        ])->assertNoContent();

        $this->postJson('/api/study/milestones/evaluate')
            ->assertOk()
            ->assertJsonCount(1, 'milestones')
            ->assertJsonCount(0, 'pendingMilestones')
            ->assertJsonPath('milestones.0.id', StudyMilestoneKey::Burned100->value);
    }

    public function test_presentation_payload_validation_and_authentication_are_enforced(): void
    {
        $this->postJson('/api/study/milestones/evaluate')->assertUnauthorized();
        $this->postJson('/api/study/milestones/present', [
            'milestoneIds' => [StudyMilestoneKey::Burned100->value],
        ])->assertUnauthorized();

        $this->actingAs(User::factory()->create());
        $this->postJson('/api/study/milestones/present', [
            'milestoneIds' => ['not-a-milestone'],
        ])->assertUnprocessable()->assertJsonValidationErrors('milestoneIds.0');
        $this->postJson('/api/study/milestones/present', [
            'milestoneIds' => [StudyMilestoneKey::Burned100->value, StudyMilestoneKey::Burned100->value],
        ])->assertUnprocessable()->assertJsonValidationErrors('milestoneIds.1');
        $this->postJson('/api/study/milestones/present', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('milestoneIds');
    }
}
