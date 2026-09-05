<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Actions\ResolveManualStudyDeckAction;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class StoreStudyCardIdempotencyApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_it_reuses_the_existing_default_study_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user, [
            'name' => ResolveManualStudyDeckAction::DEFAULT_DECK_NAME,
            'description' => 'already here',
            'is_manual_study_deck' => true,
        ]);

        $this->postJson('/api/study/cards', [
            'cardType' => 'production',
            'prompt' => ['cueText' => 'company'],
            'answer' => ['meaning' => '会社'],
        ])
            ->assertCreated()
            ->assertJsonPath('cardType', 'production');

        $this->assertSame(1, Deck::query()->count());
        $this->assertTrue($deck->refresh()->is_manual_study_deck);
        $this->assertSame($deck->id, Card::query()->sole()->deck_id);
        $this->assertSame(1, SyncFeedEntry::query()->count());
        $this->assertSame('card', SyncFeedEntry::query()->sole()->resource_type);
    }

    public function test_it_accepts_a_client_provided_card_id_for_idempotent_retries(): void
    {
        $this->signIn();
        $id = strtolower((string) Str::ulid());

        $payload = [
            'id' => strtoupper($id),
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
        ];

        $firstResponse = $this->postJson('/api/study/cards', $payload);
        $secondResponse = $this->postJson('/api/study/cards', $payload);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('cardType', 'recognition')
            ->assertJsonPath('prompt.cueText', '会社')
            ->assertJsonPath('answer.meaning', 'company');

        $secondResponse
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('cardType', 'recognition')
            ->assertJsonPath('prompt.cueText', '会社')
            ->assertJsonPath('answer.meaning', 'company');

        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($firstResponse->json(), 'first idempotent card payload');
        $this->assertStudyCardSummaryCompatibilityPayloadHasShape($secondResponse->json(), 'second idempotent card payload');

        $this->assertSame(1, Card::query()->count());
        $this->assertSame(1, Deck::query()->count());
        $this->assertSame(2, SyncFeedEntry::query()->count());
    }

    public function test_it_rate_limits_manual_card_creation_by_user(): void
    {
        $limiter = new StudyCardCreateRateLimiter;
        $clientIp = '127.0.0.1';
        $testBucket = 'test-'.Str::ulid();
        $user = $this->signIn();
        $otherUser = User::factory()->create();
        $previousServerVariables = $this->serverVariables;

        $restoreStudyCardCreateLimiter = function () use ($limiter): void {
            RateLimiter::for(StudyCardCreateRateLimiter::NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        $userKey = $testBucket.'|'.$limiter->keyFor($user->id, $clientIp);
        $otherUserKey = $testBucket.'|'.$limiter->keyFor($otherUser->id, $clientIp);

        try {
            $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

            RateLimiter::for(StudyCardCreateRateLimiter::NAME, function (Request $request) use ($limiter, $testBucket): Limit {
                return Limit::perMinute(3)->by(
                    $testBucket.'|'.$limiter->keyFor($request->user()?->getAuthIdentifier(), $request->ip()),
                );
            });

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $this
                    ->postJson('/api/study/cards', [])
                    ->assertUnprocessable();
            }

            $this->signIn($otherUser);

            $this
                ->postJson('/api/study/cards', [])
                ->assertUnprocessable();

            $this->signIn($user);

            $this
                ->postJson('/api/study/cards', [])
                ->assertTooManyRequests();

            $this->assertSame(0, Card::query()->count());
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreStudyCardCreateLimiter();
            $this->withServerVariables($previousServerVariables);
        }
    }

    public function test_it_rejects_client_provided_card_id_conflicts(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user, [
            'name' => ResolveManualStudyDeckAction::DEFAULT_DECK_NAME,
            'is_manual_study_deck' => true,
        ]);
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'old front',
            'back_text' => 'old back',
        ]);

        $this->postJson('/api/study/cards', [
            'id' => $id,
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'new front'],
            'answer' => ['meaning' => 'old back'],
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Card ID already exists with different metadata.')
            ->assertJsonPath('reason', 'card_id_conflict');

        $this->assertSame(1, Card::query()->count());
    }

    public function test_it_hides_cross_user_client_provided_card_id_conflicts(): void
    {
        $this->signIn();
        $id = strtolower((string) Str::ulid());

        Card::factory()
            ->for($this->deckFor(User::factory()->create()))
            ->create(['id' => $id]);

        $this->postJson('/api/study/cards', [
            'id' => $id,
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');

        $this->assertSame(1, Card::query()->count());
    }

    public function test_it_returns_gone_for_owned_deleted_client_provided_card_id_conflicts(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());
        $deletedCard = $this->cardFor($user, ['id' => $id]);

        $deletedCard->delete();

        $this->postJson('/api/study/cards', [
            'id' => $id,
            'cardType' => 'recognition',
            'prompt' => ['cueText' => 'front'],
            'answer' => ['meaning' => 'back'],
        ])
            ->assertStatus(410)
            ->assertJsonPath('message', 'Card ID belongs to a deleted card.')
            ->assertJsonPath('reason', 'card_deleted');

        $this->assertSame(1, Card::withTrashed()->count());
        $this->assertTrue($deletedCard->refresh()->trashed());
    }
}
