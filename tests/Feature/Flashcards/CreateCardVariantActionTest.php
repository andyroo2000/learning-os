<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsCardSyncFeedEntries;
use Tests\TestCase;

class CreateCardVariantActionTest extends TestCase
{
    use AssertsCardSyncFeedEntries;
    use RefreshDatabase;

    public function test_client_id_retries_compare_variant_timestamps_at_persisted_precision(): void
    {
        $deck = Deck::factory()->create();
        $cardId = strtolower((string) Str::ulid());
        $variantUnlockedAt = Carbon::parse('2026-06-04T14:15:30.987654Z');

        $data = CreateCardData::fromInput(
            userId: $deck->user_id,
            deckId: $deck->id,
            frontText: 'front',
            backText: 'back',
            variantGroupId: 'group-1',
            variantSentenceId: 'sentence-1',
            variantKind: VocabVariantKind::SentenceAudioRecognition,
            variantStage: 1,
            variantStatus: VocabVariantStatus::Available,
            variantUnlockedAt: $variantUnlockedAt,
            id: $cardId,
        );

        $firstResult = app(CreateCardAction::class)->handle($data);
        $secondResult = app(CreateCardAction::class)->handle($data);

        $this->assertTrue($firstResult->wasCreated);
        $this->assertFalse($secondResult->wasCreated);
        $this->assertSame($cardId, $secondResult->card->id);
        $this->assertSame(1, Card::query()->count());

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $this->assertCardSyncPayloadRecorded($firstResult->card->refresh(), SyncFeedOperation::Create);
    }

    public function test_locked_variants_do_not_receive_new_queue_positions(): void
    {
        $deck = Deck::factory()->create();

        $locked = app(CreateCardAction::class)->handle(CreateCardData::fromInput(
            userId: $deck->user_id,
            deckId: $deck->id,
            frontText: 'locked front',
            backText: 'locked back',
            variantStatus: VocabVariantStatus::Locked,
        ))->card->refresh();
        $available = app(CreateCardAction::class)->handle(CreateCardData::fromInput(
            userId: $deck->user_id,
            deckId: $deck->id,
            frontText: 'available front',
            backText: 'available back',
            variantStatus: VocabVariantStatus::Available,
        ))->card->refresh();

        $this->assertNull($locked->new_queue_position);
        $this->assertSame(1, $available->new_queue_position);
        $this->assertCardSyncPayloadRecorded($locked, SyncFeedOperation::Create);
        $this->assertCardSyncPayloadRecorded($available, SyncFeedOperation::Create);
    }

    public function test_it_treats_blank_variant_enum_metadata_as_absent_for_direct_callers(): void
    {
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'front',
                backText: 'back',
                variantGroupId: '   ',
                variantSentenceId: "\t",
                variantKind: '   ',
                variantStatus: "\n",
            ),
        );

        $card = $result->card->refresh();

        $this->assertNull($card->variant_group_id);
        $this->assertNull($card->variant_sentence_id);
        $this->assertNull($card->variant_kind);
        $this->assertNull($card->variant_status);

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $this->assertCardSyncPayloadRecorded($card, SyncFeedOperation::Create);
    }

    #[DataProvider('invalidVariantMetadataProvider')]
    public function test_it_rejects_invalid_variant_metadata_for_direct_callers(array $overrides, string $message): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        CreateCardData::fromInput(...array_merge([
            'userId' => $deck->user_id,
            'deckId' => $deck->id,
            'frontText' => 'front',
            'backText' => 'back',
        ], $overrides));
    }

    public function test_it_creates_a_card_with_a_client_card_type(): void
    {
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                cardType: ' PRODUCTION ',
            ),
        );

        $this->assertSame(CardType::Production, $result->card->card_type);
        $this->assertDatabaseHas('cards', [
            'id' => $result->card->id,
            'card_type' => 'production',
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertCardSyncPayloadRecorded($result->card->refresh(), SyncFeedOperation::Create);

        $this->assertSame('production', $entry->payload['card_type']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidVariantMetadataProvider(): array
    {
        return [
            'oversized variant group id' => [
                ['variantGroupId' => str_repeat('a', 65)],
                'Card variant IDs must be 64 characters or fewer.',
            ],
            'oversized variant sentence id' => [
                ['variantSentenceId' => str_repeat('a', 65)],
                'Card variant IDs must be 64 characters or fewer.',
            ],
            'malformed variant kind' => [
                ['variantKind' => 'not-a-kind'],
                'Variant kind must be one of: sentence_audio_recognition, sentence_text_recognition, word_audio_recognition, word_text_recognition, sentence_cloze, sentence_production.',
            ],
            'malformed variant status' => [
                ['variantStatus' => 'unknown'],
                'Variant status must be one of: available, locked.',
            ],
            'negative variant stage' => [
                ['variantStage' => -1],
                'Card variant stage must be between 1 and 65535.',
            ],
            'zero variant stage' => [
                ['variantStage' => 0],
                'Card variant stage must be between 1 and 65535.',
            ],
            'oversized variant stage' => [
                ['variantStage' => 65536],
                'Card variant stage must be between 1 and 65535.',
            ],
        ];
    }
}
