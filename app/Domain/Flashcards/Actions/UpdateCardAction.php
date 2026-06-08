<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Results\UpdateCardResult;
use App\Domain\Flashcards\Support\CardSearchText;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateCardAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(Card $card, UpdateCardData $data): UpdateCardResult
    {
        if ($data->frontText === '') {
            throw new InvalidArgumentException('Card front text is required.');
        }

        if ($data->backText === '') {
            throw new InvalidArgumentException('Card back text is required.');
        }

        return DB::transaction(function () use ($card, $data): UpdateCardResult {
            $card->front_text = $data->frontText;
            $card->back_text = $data->backText;

            if ($data->cardType !== null) {
                $card->card_type = $data->cardType;
            }

            if ($data->hasPromptJson) {
                $card->prompt_json = $data->promptJson;
            }

            if ($data->hasAnswerJson) {
                $card->answer_json = $data->answerJson;
            }

            if ($data->hasVariantGroupId) {
                $card->variant_group_id = $data->variantGroupId;
            }

            if ($data->hasVariantSentenceId) {
                $card->variant_sentence_id = $data->variantSentenceId;
            }

            // Card stores variant enums as scalar metadata, so compare scalar values before assignment
            // to avoid sync entries from enum-object/string dirty tracking differences.
            if ($data->hasVariantKind) {
                if ($this->variantEnumValue($card->variant_kind) !== $data->variantKind?->value) {
                    $card->variant_kind = $data->variantKind?->value;
                }
            }

            if ($data->hasVariantStage) {
                if ($this->variantStageValue($card->variant_stage) !== $data->variantStage) {
                    $card->variant_stage = $data->variantStage;
                }
            }

            if ($data->hasVariantStatus) {
                if ($this->variantEnumValue($card->variant_status) !== $data->variantStatus?->value) {
                    $card->variant_status = $data->variantStatus?->value;
                }
            }

            if ($data->hasVariantUnlockedAt) {
                if ($this->timestampJson($card->variant_unlocked_at) !== $this->timestampJson($data->variantUnlockedAt)) {
                    $card->variant_unlocked_at = $data->variantUnlockedAt;
                }
            }

            $contentWasUpdated = $card->isDirty(['front_text', 'back_text', 'prompt_json', 'answer_json']);

            if ($contentWasUpdated) {
                $card->search_text = CardSearchText::fromContent(
                    frontText: $card->front_text,
                    backText: $card->back_text,
                    promptJson: $card->prompt_json,
                    answerJson: $card->answer_json,
                );
            }

            $wasUpdated = $card->isDirty([
                'front_text',
                'back_text',
                'card_type',
                'prompt_json',
                'answer_json',
                'variant_group_id',
                'variant_sentence_id',
                'variant_kind',
                'variant_stage',
                'variant_status',
                'variant_unlocked_at',
                ...($contentWasUpdated ? ['search_text'] : []),
            ]);

            $card->saveOrFail();

            if (! $wasUpdated) {
                return UpdateCardResult::unchanged($card);
            }

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $card->ownerUserId(),
                    domain: CardSyncPayload::DOMAIN,
                    resourceType: CardSyncPayload::RESOURCE_TYPE,
                    resourceId: $card->id,
                    operation: SyncFeedOperation::Update->value,
                    payload: CardSyncPayload::fromCard($card),
                ),
            );

            return UpdateCardResult::updated($card);
        });
    }

    private function timestampJson(?DateTimeInterface $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::instance($timestamp)->utc()->startOfSecond()->toJSON();
    }

    private function variantEnumValue(BackedEnum|string|int|null $value): string|int|null
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        return $value;
    }

    private function variantStageValue(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }
}
