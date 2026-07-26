<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Actions\ListStudyCardBatchAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListStudyCardBatchActionTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_returns_owned_active_cards_in_input_order(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $first = $this->cardWithStudyStatus($deck, CardStudyStatus::Review);
        $second = $this->cardWithStudyStatus($deck, CardStudyStatus::New);
        $other = $this->cardWithStudyStatus(
            $this->deckFor(User::factory()->create()),
            CardStudyStatus::Review,
        );
        $deletedDeck = $this->deckFor($user);
        $deleted = $this->cardWithStudyStatus($deletedDeck, CardStudyStatus::Review);
        $deletedDeck->delete();
        $missingId = strtolower((string) Str::ulid());

        $cards = app(ListStudyCardBatchAction::class)->handle(
            $user->id,
            [strtoupper($second->id), $other->id, $missingId, $deleted->id, $first->id],
        );

        $this->assertSame([$second->id, $first->id], $cards->pluck('id')->all());
    }

    public function test_it_accepts_the_maximum_batch_size(): void
    {
        $user = User::factory()->create();
        $deck = $this->deckFor($user);
        $cards = collect(range(1, ListStudyCardBatchAction::MAX_ITEMS))
            ->map(fn () => $this->cardWithStudyStatus($deck, CardStudyStatus::Review));
        $requestedIds = $cards->pluck('id')->reverse()->values()->all();

        $result = app(ListStudyCardBatchAction::class)->handle($user->id, $requestedIds);

        $this->assertSame($requestedIds, $result->pluck('id')->all());
    }

    /**
     * @param  list<mixed>  $ids
     */
    #[DataProvider('invalidBatchProvider')]
    public function test_it_rejects_invalid_direct_batches(array $ids, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        app(ListStudyCardBatchAction::class)->handle(User::factory()->create()->id, $ids);
    }

    /**
     * @return array<string, array{list<mixed>, string}>
     */
    public static function invalidBatchProvider(): array
    {
        $id = strtolower((string) Str::ulid());

        return [
            'empty' => [
                [],
                'Study card batches must contain between 1 and '.ListStudyCardBatchAction::MAX_ITEMS.' IDs.',
            ],
            'too many' => [
                array_fill(0, ListStudyCardBatchAction::MAX_ITEMS + 1, $id),
                'Study card batches must contain between 1 and '.ListStudyCardBatchAction::MAX_ITEMS.' IDs.',
            ],
            'non-string' => [
                [123],
                'Study card batches must contain between 1 and '.ListStudyCardBatchAction::MAX_ITEMS.' IDs.',
            ],
            'malformed' => [['not-an-id'], 'Study card batch IDs must be distinct ULIDs.'],
            'duplicate after normalization' => [
                [$id, strtoupper($id)],
                'Study card batch IDs must be distinct ULIDs.',
            ],
        ];
    }
}
