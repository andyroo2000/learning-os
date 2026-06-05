<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Actions\ListStudyNewCardQueueAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListStudyNewCardQueueActionTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_lists_new_cards_without_a_search_query_from_the_zero_offset(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $page = app(ListStudyNewCardQueueAction::class)->handle(
            userId: $user->id,
            cursor: 0,
            limit: 100,
        );

        $this->assertSame(2, $page['total']);
        $this->assertSame(100, $page['limit']);
        $this->assertNull($page['nextCursor']);
        $this->assertSame([$firstCard->id, $secondCard->id], $page['items']->pluck('id')->all());
    }

    public function test_it_lists_searchable_new_cards_with_offset_cursor_pagination(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'front_text' => '会社',
            'back_text' => 'company',
            'search_text' => '会社 company',
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'front_text' => '株式会社',
            'back_text' => 'corporation',
            'search_text' => '株式会社 corporation',
            'new_queue_position' => 2,
        ]);
        $legacyNullPositionCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'front_text' => '会社員',
            'back_text' => 'employee',
            'search_text' => '会社員 employee',
            'new_queue_position' => null,
        ]);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'front_text' => '学校',
            'back_text' => 'school',
            'search_text' => '学校 school',
            'new_queue_position' => 3,
        ]);
        $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::New, [
            'front_text' => '会社',
            'back_text' => 'company',
            'search_text' => '会社 company other user',
            'new_queue_position' => 1,
        ]);

        $firstPage = app(ListStudyNewCardQueueAction::class)->handle(
            userId: $user->id,
            limit: 2,
            q: '会社',
        );

        $this->assertSame(3, $firstPage['total']);
        $this->assertSame(2, $firstPage['limit']);
        $this->assertSame('2', $firstPage['nextCursor']);
        $this->assertSame([$firstCard->id, $secondCard->id], $firstPage['items']->pluck('id')->all());

        $secondPage = app(ListStudyNewCardQueueAction::class)->handle(
            userId: $user->id,
            cursor: 2,
            limit: 2,
            q: '会社',
        );

        $this->assertSame(3, $secondPage['total']);
        $this->assertNull($secondPage['nextCursor']);
        $this->assertSame([$legacyNullPositionCard->id], $secondPage['items']->pluck('id')->all());
    }
}
