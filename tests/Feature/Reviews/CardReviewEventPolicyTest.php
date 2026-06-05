<?php

namespace Tests\Feature\Reviews;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class CardReviewEventPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_a_user_to_view_their_own_review_event(): void
    {
        $user = User::factory()->create();
        $reviewEvent = $this->cardReviewEventFor($user);

        $response = Gate::forUser($user)->inspect('view', $reviewEvent);

        $this->assertTrue($response->allowed());
    }

    public function test_it_allows_a_user_to_delete_their_own_review_event(): void
    {
        $user = User::factory()->create();
        $reviewEvent = $this->cardReviewEventFor($user);

        $response = Gate::forUser($user)->inspect('delete', $reviewEvent);

        $this->assertTrue($response->allowed());
    }

    public function test_it_hides_another_users_review_event_when_viewing(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $reviewEvent = $this->cardReviewEventFor($otherUser);

        $response = Gate::forUser($user)->inspect('view', $reviewEvent);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    public function test_it_hides_a_review_event_for_a_soft_deleted_card_when_viewing(): void
    {
        $user = User::factory()->create();
        $reviewEvent = $this->cardReviewEventFor($user);

        $reviewEvent->card->delete();

        $response = Gate::forUser($user)->inspect('view', $reviewEvent);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    public function test_it_hides_a_review_event_for_a_card_in_a_soft_deleted_deck_when_viewing(): void
    {
        $user = User::factory()->create();
        $reviewEvent = $this->cardReviewEventFor($user);

        $reviewEvent->card->deck->delete();

        $response = Gate::forUser($user)->inspect('view', $reviewEvent);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }
}
