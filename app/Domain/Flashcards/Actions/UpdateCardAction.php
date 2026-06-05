<?php

namespace App\Domain\Flashcards\Actions;

use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Results\UpdateCardResult;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
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

            $wasUpdated = $card->isDirty(['front_text', 'back_text', 'card_type', 'prompt_json', 'answer_json']);

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
}
