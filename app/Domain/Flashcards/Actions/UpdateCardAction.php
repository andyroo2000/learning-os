<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Results\UpdateCardResult;
use App\Domain\Flashcards\Support\CardSearchText;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Study\Actions\MatchLearningConceptsForCardAction;
use App\Domain\Study\Enums\LearningConceptMatchSource;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class UpdateCardAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
        private readonly ?MatchLearningConceptsForCardAction $matchLearningConcepts = null,
        private readonly ?NewCardQueuePosition $newCardQueuePosition = null,
    ) {}

    public function handle(Card $card, UpdateCardData $data): UpdateCardResult
    {
        if ($data->hasFrontText && $data->frontText === '') {
            throw new InvalidArgumentException('Card front text is required.');
        }

        if ($data->hasBackText && $data->backText === '') {
            throw new InvalidArgumentException('Card back text is required.');
        }

        $result = DB::transaction(function () use ($card, $data): UpdateCardResult {
            // Queue writers serialize on the owner before card rows. Reserve a possible
            // position in that order, then re-check the live card after locking it.
            $needsAvailableQueuePosition = $data->hasVariantStatus
                && $data->variantStatus !== VocabVariantStatus::Locked;
            $ownerId = $card->ownerUserId();
            $hasCurrentOrRequestedProgressionGroup = (
                is_string($card->variant_group_id)
                && trim($card->variant_group_id) !== ''
            )
                || ($data->hasVariantGroupId && is_string($data->variantGroupId) && trim($data->variantGroupId) !== '');

            if ($needsAvailableQueuePosition) {
                $availableQueuePosition = $this->newCardQueuePosition()->nextForUser($ownerId);
            } else {
                $availableQueuePosition = null;

                if ($hasCurrentOrRequestedProgressionGroup) {
                    $this->newCardQueuePosition()->lockOwner($ownerId);
                }
            }

            // Route authorization happens before this transaction. Re-resolve the live card
            // under the same row lock used by deletion so a stale model cannot append an
            // Update feed entry after its Delete tombstone.
            $lockedCard = Card::query()
                ->whereKey($card->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedCard === null) {
                throw (new ModelNotFoundException)->setModel(Card::class, [$card->getKey()]);
            }

            $card->setRawAttributes($lockedCard->getAttributes(), true);
            $card->setRelations([]);

            if ($data->hasFrontText) {
                $card->front_text = $data->frontText;
            }

            if ($data->hasBackText) {
                $card->back_text = $data->backText;
            }

            if ($data->cardType !== null) {
                $card->card_type = $data->cardType;
            }

            if ($data->hasPromptJson) {
                $card->prompt_json = $data->promptJson;
            }

            if ($data->hasAnswerJson) {
                $card->answer_json = $data->answerJson;
            }

            if ($data->hasAnswerAudioSource) {
                $card->answer_audio_source = $data->answerAudioSource;
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

                if (($card->study_status ?? CardStudyStatus::New) === CardStudyStatus::New) {
                    if ($data->variantStatus === VocabVariantStatus::Locked) {
                        $card->new_queue_position = null;
                    } elseif ($card->new_queue_position === null) {
                        $card->new_queue_position = $availableQueuePosition;
                    }
                }
            }

            if ($data->hasVariantUnlockedAt) {
                if ($this->timestampJson($card->variant_unlocked_at) !== $this->timestampJson($data->variantUnlockedAt)) {
                    $card->variant_unlocked_at = $data->variantUnlockedAt;
                }
            }

            if ($data->hasVariantGroupId
                || $data->hasVariantStage
                || $data->hasVariantStatus
                || $data->hasVariantUnlockedAt
            ) {
                // Retirement is a server-owned progression result. Explicit authoring
                // changes invalidate that historic membership state.
                $card->variant_retired_at = null;
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
                'answer_audio_source',
                'variant_group_id',
                'variant_sentence_id',
                'variant_kind',
                'variant_stage',
                'variant_status',
                'variant_unlocked_at',
                'new_queue_position',
                ...($contentWasUpdated ? ['search_text'] : []),
            ]);

            if (! $wasUpdated) {
                return UpdateCardResult::unchanged($card);
            }

            $card->saveOrFail();

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

            if ($contentWasUpdated) {
                DB::afterCommit(fn () => $this->matchLearningConceptsBestEffort($card));
            }

            return UpdateCardResult::updated($card);
        });

        return $result;
    }

    private function matchLearningConceptsBestEffort(Card $card): void
    {
        try {
            ($this->matchLearningConcepts ?? app(MatchLearningConceptsForCardAction::class))
                ->handle($card, LearningConceptMatchSource::Creation);
        } catch (Throwable $exception) {
            // Analytics are best-effort and the resumable backfill repairs missed matches.
            Log::warning('Learning concept matching failed after card update.', [
                'card_id' => $card->getKey(),
                'exception' => $exception,
            ]);
        }
    }

    private function timestampJson(?DateTimeInterface $timestamp): ?string
    {
        // Cards persist review timestamps at second precision, so variant metadata compares the same way.
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

    private function newCardQueuePosition(): NewCardQueuePosition
    {
        return $this->newCardQueuePosition ?? app(NewCardQueuePosition::class);
    }
}
