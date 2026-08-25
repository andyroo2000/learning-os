<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Actions\ListStudyLearningItemsAction;
use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Http\Controllers\Api\Study\ListStudyLearningItemsController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListStudyLearningItemsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_presents_a_path_as_one_learning_item_and_keeps_standalone_cards(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $groupId = 'context-to-production';
        $sentence = Card::factory()->for($deck)->create([
            'front_text' => '会社で日本語を話します。',
            'back_text' => 'I speak Japanese at work.',
            'variant_group_id' => $groupId,
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Available->value,
            'created_at' => now()->subMinutes(3),
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => '日本語の勉強は楽しいです。',
            'back_text' => 'Studying Japanese is fun.',
            'variant_group_id' => $groupId,
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Available->value,
            'created_at' => now()->subMinutes(3),
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => '日本語',
            'back_text' => 'Japanese language',
            'variant_group_id' => $groupId,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked->value,
            'created_at' => now()->subMinutes(2),
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'Produce 日本語',
            'back_text' => '日本語',
            'variant_group_id' => $groupId,
            'variant_stage' => 3,
            'variant_status' => VocabVariantStatus::Locked->value,
            'created_at' => now()->subMinute(),
        ]);
        $standalone = Card::factory()->for($deck)->create([
            'front_text' => '猫',
            'back_text' => 'cat',
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/study/learning-items?per_page=10');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.id', 'card:'.$standalone->clientId())
            ->assertJsonPath('items.0.groupId', null)
            ->assertJsonPath('items.0.stageCount', 1)
            ->assertJsonPath('items.0.cardCount', 1)
            ->assertJsonPath('items.1.id', 'path:'.$groupId)
            ->assertJsonPath('items.1.groupId', $groupId)
            ->assertJsonPath('items.1.representativeCard.id', $sentence->clientId())
            ->assertJsonPath('items.1.currentStageNumber', 1)
            ->assertJsonPath('items.1.stageCount', 3)
            ->assertJsonPath('items.1.cardCount', 4)
            ->assertJsonPath('items.1.retiredStageCount', 0)
            ->assertJsonPath('items.1.transferDemonstrated', false)
            ->assertJsonPath('items.1.stages.0.number', 1)
            ->assertJsonPath('items.1.stages.0.status', VocabVariantStatus::Available->value)
            ->assertJsonPath('items.1.stages.0.cardCount', 2)
            ->assertJsonCount(2, 'items.1.stages.0.cards')
            ->assertJsonPath('items.1.stages.0.cards.0.id', $sentence->clientId())
            ->assertJsonPath('items.1.stages.0.cards.0.syncId', $sentence->id)
            ->assertJsonPath('items.1.stages.1.status', VocabVariantStatus::Locked->value)
            ->assertJsonPath('limit', 10)
            ->assertJsonPath('nextCursor', null);
    }

    public function test_searching_any_stage_returns_the_complete_family_without_crossing_owners(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $groupId = 'shared-group-id';
        Card::factory()->for($deck)->create([
            'front_text' => '会社で働きます。',
            'back_text' => 'I work at a company.',
            'variant_group_id' => $groupId,
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Available->value,
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => '会社',
            'back_text' => 'company needle',
            'variant_group_id' => $groupId,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'unrelated',
            'back_text' => 'standalone',
        ]);

        $otherDeck = $this->deckFor(User::factory()->create());
        Card::factory()->for($otherDeck)->create([
            'front_text' => 'private needle',
            'variant_group_id' => $groupId,
            'variant_stage' => 3,
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);
        $deletedDeck = $this->deckFor($user);
        Card::factory()->for($deletedDeck)->create([
            'front_text' => 'deleted needle',
            'variant_group_id' => $groupId,
            'variant_stage' => 3,
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);
        $deletedDeck->delete();

        $this->getJson('/api/study/learning-items?q=needle')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', 'path:'.$groupId)
            ->assertJsonPath('items.0.cardCount', 2)
            ->assertJsonCount(2, 'items.0.stages');

        $this->getJson('/api/study/learning-items?q=private%20needle')
            ->assertOk()
            ->assertJsonCount(0, 'items');
        $this->getJson('/api/study/learning-items?q=deleted%20needle')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_it_cursor_paginates_whole_learning_items_in_stable_order(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $sameTime = Carbon::parse('2026-08-25T12:00:00Z');
        $groupId = 'paginated-family';
        $groupCards = Card::factory()->count(3)->for($deck)->create([
            'variant_group_id' => $groupId,
            'variant_status' => VocabVariantStatus::Available->value,
            'created_at' => $sameTime,
            'updated_at' => $sameTime,
        ]);
        foreach ($groupCards->values() as $index => $card) {
            $card->forceFill(['variant_stage' => $index + 1])->saveQuietly();
        }
        $standalone = Card::factory()->for($deck)->create([
            'created_at' => $sameTime->subSecond(),
            'updated_at' => $sameTime->subSecond(),
        ]);

        $firstPage = $this->getJson('/api/study/learning-items?per_page=1');
        $firstPage
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', 'path:'.$groupId)
            ->assertJsonPath('items.0.cardCount', 3);
        $cursor = $firstPage->json('nextCursor');
        $this->assertIsString($cursor);

        $this->getJson('/api/study/learning-items?per_page=1&cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', 'card:'.$standalone->clientId())
            ->assertJsonPath('nextCursor', null);
    }

    public function test_it_marks_transfer_when_only_the_final_stage_remains_available(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $groupId = 'completed-transfer';

        foreach ([1, 2, 3] as $stage) {
            Card::factory()->for($deck)->create([
                'variant_group_id' => $groupId,
                'variant_stage' => $stage,
                'variant_status' => $stage === 3
                    ? VocabVariantStatus::Available->value
                    : VocabVariantStatus::Locked->value,
                'study_status' => $stage === 3
                    ? CardStudyStatus::Review->value
                    : CardStudyStatus::Suspended->value,
                'variant_retired_at' => $stage === 3 ? null : now()->subMinute(),
            ]);
        }

        $this->getJson('/api/study/learning-items')
            ->assertOk()
            ->assertJsonPath('items.0.currentStageNumber', 3)
            ->assertJsonPath('items.0.retiredStageCount', 2)
            ->assertJsonPath('items.0.transferDemonstrated', true);
    }

    public function test_a_manually_suspended_locked_stage_is_not_reported_as_retired(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $groupId = 'manual-suspend';
        Card::factory()->for($deck)->create([
            'variant_group_id' => $groupId,
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Locked->value,
            'study_status' => CardStudyStatus::Suspended->value,
            'variant_retired_at' => null,
        ]);
        Card::factory()->for($deck)->create([
            'variant_group_id' => $groupId,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Available->value,
            'study_status' => CardStudyStatus::Review->value,
        ]);

        $this->getJson('/api/study/learning-items')
            ->assertOk()
            ->assertJsonPath('items.0.stages.0.status', VocabVariantStatus::Locked->value)
            ->assertJsonPath('items.0.retiredStageCount', 0)
            ->assertJsonPath('items.0.transferDemonstrated', false);
    }

    public function test_it_loads_a_page_of_grouped_items_without_per_family_queries(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        foreach (['first-family', 'second-family'] as $groupId) {
            foreach ([1, 2] as $stage) {
                Card::factory()->for($deck)->create([
                    'variant_group_id' => $groupId,
                    'variant_stage' => $stage,
                    'variant_status' => $stage === 1
                        ? VocabVariantStatus::Available->value
                        : VocabVariantStatus::Locked->value,
                ]);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = app(ListStudyLearningItemsAction::class)->handle($user->id);

        $this->assertCount(2, $result['items']);
        $this->assertCount(2, DB::getQueryLog());
    }

    public function test_it_validates_pagination_search_and_requires_compatibility_limits(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/study/learning-items?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/study/learning-items?q=%20%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
        $this->getJson('/api/study/learning-items?cursor=not-a-cursor')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor']);

        $route = app('router')->getRoutes()->getByAction(ListStudyLearningItemsController::class);
        $this->assertNotNull($route);
        $this->assertContains(
            'throttle:'.StudyCompatibilityTrafficRateLimiter::NETWORK_NAME,
            $route->gatherMiddleware(),
        );
        $this->assertContains(
            'throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME,
            $route->gatherMiddleware(),
        );
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/study/learning-items')->assertUnauthorized();
    }
}
